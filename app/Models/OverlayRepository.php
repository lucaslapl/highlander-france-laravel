<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Http\UploadedFile;

/**
 * Persistance des overlays de stats match (outil admin « Overlay match »).
 *
 * Les overlays vivent dans des caches JSON sous hlfr_data_path('overlays/') :
 * un index (liste des overlays) et un payload complet par overlay. Les
 * avatars d'équipe uploadés sont stockés sous storage/app/public/
 * overlay-avatars/ et servis par la route /overlay/{token}/avatar/{team}.
 *
 * Aucun modèle Eloquent : état 100% fichiers, régénérable depuis logs.tf.
 */
final class OverlayRepository
{
    /** Extensions d'avatar autorisées (upload). */
    private const AVATAR_EXTENSIONS = ['jpg', 'png', 'webp'];

    /** Taille maximale d'un avatar en Ko. */
    private const MAX_AVATAR_KB = 2048;

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
     * Enregistre l'avatar uploadé d'une équipe (remplace l'ancien).
     * Retourne false si l'extension n'est pas autorisée.
     */
    public function saveAvatar(string $token, string $team, UploadedFile $file): bool
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        if (! in_array($ext, self::AVATAR_EXTENSIONS, true)) {
            return false;
        }

        $this->deleteAvatar($token, $team);

        $dir = $this->avatarDir($token);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $target = $dir.'/'.$team.'.'.$ext;
        move_uploaded_file((string) $file->getRealPath(), $target)
            ?: @copy((string) $file->getRealPath(), $target);

        return is_file($target);
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
     * Taille maximale d'un avatar en Ko (exposée à la validation).
     */
    public function maxAvatarKb(): int
    {
        return self::MAX_AVATAR_KB;
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

    private function avatarDir(string $token): string
    {
        return storage_path('app/public/overlay-avatars/'.$token);
    }
}
