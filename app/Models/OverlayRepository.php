<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Persistance des overlays de stats match (outil admin « Overlay Logs »).
 *
 * Les overlays vivent dans des caches JSON sous hlfr_data_path('overlays/') :
 * un index (liste des overlays) et un payload complet par overlay. Les
 * avatars d'équipe sont fournis par URL externe, mémorisées dans un cache
 * dédié (avatar_urls.json) pour être proposées à la prochaine génération.
 * Les avatars historiques uploadés restent stockés sous
 * storage/app/public/overlay-avatars/ et servis par la route
 * /overlay/{token}/avatar/{team} (l'upload n'est plus proposé).
 *
 * Aucun modèle Eloquent : état 100% fichiers, régénérable depuis logs.tf.
 */
final class OverlayRepository
{
    /** Extensions d'avatar autorisées (fichiers historiques). */
    private const AVATAR_EXTENSIONS = ['jpg', 'png', 'webp'];

    /** Nombre d'URL d'avatars mémorisées au maximum. */
    private const MAX_REMEMBERED_AVATAR_URLS = 20;

    private string $dir;

    public function __construct()
    {
        $this->dir = hlfr_data_path('overlays');
    }

    /**
     * Liste des overlays (index), du plus récent au plus ancien.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $entries = $this->readIndex();
        usort($entries, static fn (array $a, array $b): int => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

        return $entries;
    }

    /**
     * Payload complet d'un overlay, ou null si le token est inconnu.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $token): ?array
    {
        $file = $this->payloadFile($token);
        if (! preg_match('/^[a-z0-9]{16}$/', $token) || ! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Persiste un overlay (payload + entrée d'index) en bumpant sa version,
     * que la vue overlay interroge pour se rafraîchir dans OBS.
     *
     * @param  array<string, mixed>  $overlay
     */
    public function save(array $overlay): void
    {
        $token = (string) ($overlay['token'] ?? '');
        if ($token === '') {
            return;
        }

        $overlay['version'] = time();
        $overlay['updated_at'] = time();

        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        @file_put_contents($this->payloadFile($token), json_encode($overlay), LOCK_EX);

        $index = $this->readIndex();
        $entry = [
            'token' => $token,
            'log_id' => (int) ($overlay['log_id'] ?? 0),
            'title' => (string) ($overlay['title'] ?? ''),
            'map' => (string) ($overlay['map'] ?? ''),
            'date' => (int) ($overlay['date'] ?? 0),
            'created_at' => (int) ($overlay['created_at'] ?? time()),
            'updated_at' => (int) $overlay['updated_at'],
        ];

        $found = false;
        foreach ($index as $i => $existing) {
            if (($existing['token'] ?? '') === $token) {
                $index[$i] = $entry;
                $found = true;
                break;
            }
        }

        if (! $found) {
            $index[] = $entry;
        }

        $this->writeIndex($index);
    }

    /**
     * Supprime un overlay : payload, entrée d'index et avatars uploadés.
     */
    public function delete(string $token): void
    {
        if (! preg_match('/^[a-z0-9]{16}$/', $token)) {
            return;
        }

        @unlink($this->payloadFile($token));
        $this->deleteAvatar($token, 'red');
        $this->deleteAvatar($token, 'blue');

        $index = array_values(array_filter(
            $this->readIndex(),
            static fn (array $entry): bool => ($entry['token'] ?? '') !== $token
        ));
        $this->writeIndex($index);
    }

    /**
     * Supprime l'avatar uploadé d'une équipe (silencieux s'il n'existe pas).
     */
    public function deleteAvatar(string $token, string $team): void
    {
        foreach (self::AVATAR_EXTENSIONS as $ext) {
            @unlink($this->avatarDir($token).'/'.$team.'.'.$ext);
        }
    }

    /**
     * Intervertit les avatars uploadés des équipes Rouge et Bleue
     * (silencieux si l'un des deux, ou les deux, est absent).
     */
    public function swapAvatars(string $token): void
    {
        if (! preg_match('/^[a-z0-9]{16}$/', $token)) {
            return;
        }

        $red = $this->avatar($token, 'red');
        $blue = $this->avatar($token, 'blue');

        if ($red === null && $blue === null) {
            return;
        }

        // Le passage par un fichier temporaire gère les extensions
        // différentes (ex : red.png <-> blue.webp).
        $dir = $this->avatarDir($token);
        if ($red !== null) {
            @rename($red['path'], $dir.'/_swap.'.$red['ext']);
        }
        if ($blue !== null) {
            @rename($blue['path'], $dir.'/red.'.$blue['ext']);
        }
        if ($red !== null) {
            @rename($dir.'/_swap.'.$red['ext'], $dir.'/blue.'.$red['ext']);
        }
    }

    /**
     * Chemin absolu de l'avatar uploadé d'une équipe, ou null.
     *
     * @return array{path: string, ext: string}|null
     */
    public function avatar(string $token, string $team): ?array
    {
        if (! in_array($team, ['red', 'blue'], true)) {
            return null;
        }

        foreach (self::AVATAR_EXTENSIONS as $ext) {
            $path = $this->avatarDir($token).'/'.$team.'.'.$ext;
            if (is_file($path)) {
                return ['path' => $path, 'ext' => $ext];
            }
        }

        return null;
    }

    /**
     * Indique si une équipe dispose d'un avatar uploadé (pour le rendu).
     */
    public function hasAvatar(string $token, string $team): bool
    {
        return $this->avatar($token, $team) !== null;
    }

    /**
     * Mémorise une URL d'avatar pour la proposer lors des prochaines
     * générations d'overlays (les plus récentes en tête, doublons retirés).
     */
    public function rememberAvatarUrl(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        $urls = array_values(array_unique(array_merge(
            [$url],
            array_filter($this->avatarUrls(), static fn (string $existing): bool => $existing !== $url)
        )));
        $urls = array_slice($urls, 0, self::MAX_REMEMBERED_AVATAR_URLS);

        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        @file_put_contents($this->avatarUrlFile(), json_encode($urls), LOCK_EX);
    }

    /**
     * URL d'avatars déjà utilisées, de la plus récente à la plus ancienne.
     *
     * @return array<int, string>
     */
    public function avatarUrls(): array
    {
        $file = $this->avatarUrlFile();
        if (! is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter(
            $data,
            static fn (mixed $url): bool => is_string($url) && $url !== ''
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readIndex(): array
    {
        $file = $this->dir.'/index.json';
        if (! is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function writeIndex(array $entries): void
    {
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        @file_put_contents($this->dir.'/index.json', json_encode(array_values($entries)), LOCK_EX);
    }

    private function payloadFile(string $token): string
    {
        return $this->dir.'/overlay_'.$token.'.json';
    }

    private function avatarUrlFile(): string
    {
        return $this->dir.'/avatar_urls.json';
    }

    private function avatarDir(string $token): string
    {
        return storage_path('app/public/overlay-avatars/'.$token);
    }
}
