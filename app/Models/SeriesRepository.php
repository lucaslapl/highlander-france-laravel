<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Http\UploadedFile;

/**
 * Persistance des séries de matchs (playoffs) pour le suivi automatique des
 * scores via logs.tf (outil admin « Séries de matchs »).
 *
 * Une série vit dans un cache JSON sous hlfr_data_path('series/') : un index
 * (liste des séries) et un payload complet par série. Le payload contient les
 * faits bruts (journal d'événements + logs logs.tf déjà vus) ; l'état dérivé
 * (score par map, score de série) est recalculé à la lecture par
 * SeriesScoreService — un changement de logique s'applique donc aux séries
 * existantes sans migration.
 *
 * Aucun modèle Eloquent : état 100% fichiers, auditable et corrigeable.
 */
final class SeriesRepository
{
    /** Extensions d'avatar d'équipe autorisées (upload). */
    private const AVATAR_EXTENSIONS = ['jpg', 'png', 'webp'];

    /** Taille maximale d'un avatar en Ko. */
    private const MAX_AVATAR_KB = 2048;

    private string $dir;

    public function __construct()
    {
        $this->dir = hlfr_data_path('series');
    }

    /**
     * Liste des séries (index), de la plus récente à la plus ancienne.
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
     * Liste des séries dont le suivi automatique est actif (statut « live »).
     *
     * @return array<int, array<string, mixed>>
     */
    public function allLive(): array
    {
        $live = [];
        foreach ($this->all() as $entry) {
            if (($entry['status'] ?? '') === 'live') {
                $payload = $this->find((string) $entry['token']);
                if ($payload !== null) {
                    $live[] = $payload;
                }
            }
        }

        return $live;
    }

    /**
     * Payload complet d'une série, ou null si le token est inconnu.
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
     * Persiste une série (payload + entrée d'index) en bumpant sa version,
     * qu'un futur overlay OBS pourra interroger pour se rafraîchir.
     *
     * @param  array<string, mixed>  $series
     */
    public function save(array $series): void
    {
        $token = (string) ($series['token'] ?? '');
        if ($token === '') {
            return;
        }

        // Version strictement croissante : deux écritures dans la même
        // seconde doivent quand même faire recharger l'overlay OBS.
        $series['version'] = max(time(), (int) ($series['version'] ?? 0) + 1);
        $series['updated_at'] = time();

        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        @file_put_contents($this->payloadFile($token), json_encode($series), LOCK_EX);

        $index = $this->readIndex();
        $entry = [
            'token' => $token,
            'title' => (string) ($series['title'] ?? ''),
            'status' => (string) ($series['status'] ?? 'upcoming'),
            'format' => (string) ($series['format'] ?? ''),
            'created_at' => (int) ($series['created_at'] ?? time()),
            'updated_at' => (int) $series['updated_at'],
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
     * Supprime une série : payload, entrée d'index et avatars uploadés.
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
     * Ajoute un événement au journal d'une série (append-only) et persiste.
     *
     * Le journal est la seule source de vérité du score : chaque événement
     * porte sa source (« logstf », « manual », « void »), ce qui rend chaque
     * incrément auditable et annulable sans perdre l'historique.
     *
     * @param  array<string, mixed>  $event  Événement sans champ id/at.
     * @return string Identifiant de l'événement ajouté.
     */
    public function appendEvent(string $token, array $event): string
    {
        $series = $this->find($token);
        if ($series === null) {
            return '';
        }

        $journal = is_array($series['journal'] ?? null) ? $series['journal'] : [];
        $event['id'] = 'e'.(count($journal) + 1);
        $event['at'] = time();
        $journal[] = $event;
        $series['journal'] = $journal;

        $this->save($series);

        return (string) $event['id'];
    }

    /**
     * Marque un log logs.tf comme vu pour une série (compté ou rejeté avec
     * raison), pour ne jamais le retélécharger ni le compter deux fois.
     *
     * @param  array<string, string>  $seen  [log_id => 'applied'|'rejected:<raison>']
     */
    public function markSeenLogs(string $token, array $seen): void
    {
        $series = $this->find($token);
        if ($series === null || $seen === []) {
            return;
        }

        $known = is_array($series['seen_logs'] ?? null) ? $series['seen_logs'] : [];
        foreach ($seen as $logId => $status) {
            $known[(string) $logId] = $status;
        }
        $series['seen_logs'] = $known;

        $this->save($series);
    }

    /**
     * @return array<string, string>
     */
    public function seenLogs(string $token): array
    {
        $series = $this->find($token);
        $seen = is_array($series['seen_logs'] ?? null) ? $series['seen_logs'] : [];

        return $seen;
    }

    /**
     * Enregistre l'avatar d'équipe uploadé d'une série (remplace l'ancien),
     * sur le même modèle que OverlayRepository. Retourne false si
     * l'extension n'est pas autorisée.
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
        if (! in_array($team, ['red', 'blue'], true)) {
            return;
        }

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
        $data = json_decode((string) @file_get_contents($this->indexFile()), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $index
     */
    private function writeIndex(array $index): void
    {
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        @file_put_contents($this->indexFile(), json_encode(array_values($index)), LOCK_EX);
    }

    private function indexFile(): string
    {
        return $this->dir.'/index.json';
    }

    private function payloadFile(string $token): string
    {
        return $this->dir.'/'.$token.'.json';
    }

    private function avatarDir(string $token): string
    {
        return storage_path('app/public/series-avatars/'.$token);
    }
}
