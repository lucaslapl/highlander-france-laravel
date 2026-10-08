<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Persistance des overlays « rosters d'équipes » (outil admin « Overlay
 * Rosters ») : la présentation des rosters des deux équipes d'un match
 * pour les broadcasts OBS — une équipe affichée à la fois (bascule par
 * bouton depuis le panneau de réglages admin), Highlander (grille 3x3
 * des neuf classes) comme 6v6 (six classes alignées sur toute la
 * largeur).
 *
 * Même modèle que PickBanRepository : caches JSON sous
 * hlfr_data_path('rosters/') — un index (liste des overlays) et un payload
 * complet par overlay. Chaque overlay possède son token aléatoire (16
 * caractères) et son URL publique (/roster-overlay/{token}) à pointer dans
 * OBS via une source navigateur web ; la version est bumpée à chaque
 * enregistrement pour le polling de rafraîchissement de la vue overlay.
 *
 * Aucun modèle Eloquent : état 100 % fichiers, régénérable à la main.
 */
final class RostersRepository
{
    private string $dir;

    public function __construct()
    {
        $this->dir = hlfr_data_path('rosters');
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
        if (! preg_match('/^[a-z0-9]{16}$/', $token) || ! is_file($this->payloadFile($token))) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->payloadFile($token)), true);

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
            'format' => (string) ($overlay['format'] ?? 'hl'),
            'title' => trim(((string) ($overlay['eyebrow'] ?? '')).' '.((string) ($overlay['title'] ?? ''))),
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
     * Supprime un overlay : payload et entrée d'index.
     */
    public function delete(string $token): void
    {
        if (! preg_match('/^[a-z0-9]{16}$/', $token)) {
            return;
        }

        @unlink($this->payloadFile($token));

        $index = array_values(array_filter(
            $this->readIndex(),
            static fn (array $entry): bool => ($entry['token'] ?? '') !== $token
        ));
        $this->writeIndex($index);
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

        @file_put_contents($this->dir.'/index.json', json_encode(array_values($index)), LOCK_EX);
    }

    private function payloadFile(string $token): string
    {
        return $this->dir.'/'.$token.'.json';
    }
}
