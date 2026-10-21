<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Persistance des overlays de stats match (outil admin « Overlay Logs »).
 *
 * Les overlays vivent dans des caches JSON sous hlfr_data_path('overlays/') :
 * un index (liste des overlays) et un payload complet par overlay. Les
 * avatars historiques uploadés restent stockés sous
 * storage/app/public/overlay-avatars/ et servis par la route
 * /overlay/{token}/avatar/{team} (l'upload n'est plus proposé).
 *
 * Aucun modèle Eloquent : état 100% fichiers, régénérable depuis logs.tf.
 */
final class OverlayRepository
{
    /**
     * Token réservé du PREMIER overlay à URL fixe, servi par
     * /overlay/match-logs (slot 1) : l'outil « Overlay Logs » charge
     * chaque log dans le slot visé, l'URL OBS ne change jamais. Il suit
     * le même format que les tokens aléatoires (16 caractères [a-z0-9])
     * : toutes les mécaniques (payload, version, avatars) sont partagées.
     */
    public const MATCH_LOGS_TOKEN = 'matchlogs0verlay';

    /**
     * Token réservé du SECOND overlay à URL fixe, /overlay/match-logs-2 :
     * même mécanique que MATCH_LOGS_TOKEN, pour caster deux matchs en
     * simultané (deux sources OBS indépendantes, deux logs courants).
     */
    public const MATCH_LOGS_TOKEN_2 = 'matchlogs2verlay';

    /**
     * Tokens réservés des overlays à URL fixe (« slots » de l'outil «
     * Overlay Logs »), dans l'ordre des numéros d'URL :
     * slot 1 → /overlay/match-logs, slot 2 → /overlay/match-logs-2.
     *
     * @var array<int, string>
     */
    public const MATCH_LOGS_SLOTS = [
        1 => self::MATCH_LOGS_TOKEN,
        2 => self::MATCH_LOGS_TOKEN_2,
    ];

    /** Extensions d'avatar autorisées (fichiers historiques). */
    private const AVATAR_EXTENSIONS = ['jpg', 'png', 'webp'];

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
     * Payload complet de l'entrée d'historique portant un log logs.tf
     * donné (les overlays à URL fixe exclus : ils vivent chacun avec
     * leur log courant), ou null. Sert à ne pas dupliquer un log dans
     * « Logs déjà générés » quand il est rechargé par lien.
     *
     * @return array<string, mixed>|null
     */
    public function findByLogId(int $logId): ?array
    {
        foreach ($this->all() as $entry) {
            if (! in_array(($entry['token'] ?? ''), self::MATCH_LOGS_SLOTS, true)
                && (int) ($entry['log_id'] ?? 0) === $logId) {
                return $this->find((string) $entry['token']);
            }
        }

        return null;
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
     * Copie les avatars uploadés d'un overlay vers un autre (silencieux si
     * la source n'en a pas). Utilisé par le bouton « Charger ce log » :
     * les avatars historiques suivent le log vers l'overlay à URL fixe
     * visé (slot 1 ou 2).
     */
    public function copyAvatars(string $fromToken, string $toToken): void
    {
        foreach (['red', 'blue'] as $team) {
            $avatar = $this->avatar($fromToken, $team);
            if ($avatar === null || $this->hasAvatar($toToken, $team)) {
                continue;
            }

            $targetDir = $this->avatarDir($toToken);
            if (! is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            @copy($avatar['path'], $targetDir.'/'.$team.'.'.$avatar['ext']);
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
