<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Persistance des overlays « bracket / classement » (outil admin « Overlay
 * Bracket ») : brackets de playoffs et tables de classement ETF2L pour les
 * broadcasts OBS.
 *
 * Même modèle que OverlayRepository : caches JSON sous
 * hlfr_data_path('brackets/') — un index (liste des overlays) et un payload
 * complet par overlay. Chaque overlay possède son token aléatoire (16
 * caractères) et son URL publique (/bracket-overlay/{token}) à pointer dans
 * OBS via une source navigateur web ; la version est bumpée à chaque
 * enregistrement pour le polling de rafraîchissement de la vue overlay.
 *
 * Aucun modèle Eloquent : état 100 % fichiers, régénérable depuis l'API
 * ETF2L (provenance stockée dans le payload) ou à la main.
 */
final class BracketRepository
{
    private string $dir;

    public function __construct()
    {
        $this->dir = hlfr_data_path('brackets');
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
     * @param  array<string, mixed>  $bracket
     */
    public function save(array $bracket): void
    {
        $token = (string) ($bracket['token'] ?? '');
        if ($token === '') {
            return;
        }

        $bracket['version'] = time();
        $bracket['updated_at'] = time();

        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        @file_put_contents($this->payloadFile($token), json_encode($bracket), LOCK_EX);

        $index = $this->readIndex();
        $entry = [
            'token' => $token,
            'kind' => (string) ($bracket['kind'] ?? 'bracket'),
            'title' => trim(((string) ($bracket['eyebrow'] ?? '')).' '.((string) ($bracket['title'] ?? ''))),
            'created_at' => (int) ($bracket['created_at'] ?? time()),
            'updated_at' => (int) $bracket['updated_at'],
            'is_template' => (bool) ($bracket['is_template'] ?? false),
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
     * Duplique un overlay : copie intégrale du payload sous un nouveau
     * token, sans le label « modèle par défaut » (une copie se marque à
     * la main si besoin). Sert au bouton « Dupliquer » de la liste et au
     * bouton « Dupliquer le modèle » de la création.
     *
     * @return array<string, mixed>|null La copie persistée, ou null si le token est inconnu.
     */
    public function duplicate(string $token): ?array
    {
        $bracket = $this->find($token);
        if ($bracket === null) {
            return null;
        }

        $copy = $bracket;
        $copy['token'] = bin2hex(random_bytes(8));
        $copy['created_at'] = time();
        unset($copy['is_template'], $copy['version'], $copy['updated_at']);

        $this->save($copy);

        return $this->find((string) $copy['token']);
    }

    /**
     * Désigne un overlay comme « modèle par défaut » de l'outil (unique :
     * le label est retiré au précédent porteur, s'il existe).
     */
    public function setDefaultTemplate(string $token): bool
    {
        if ($this->find($token) === null) {
            return false;
        }

        foreach ($this->all() as $entry) {
            $other = (string) ($entry['token'] ?? '');
            $bracket = $other === '' ? null : $this->find($other);
            if ($bracket === null) {
                continue;
            }

            $wanted = $other === $token;
            if ((bool) ($bracket['is_template'] ?? false) === $wanted) {
                continue;
            }

            if ($wanted) {
                $bracket['is_template'] = true;
            } else {
                unset($bracket['is_template']);
            }

            $this->save($bracket);
        }

        return true;
    }

    /**
     * L'overlay portant le label « modèle par défaut », ou null.
     *
     * @return array<string, mixed>|null
     */
    public function findDefaultTemplate(): ?array
    {
        foreach ($this->all() as $entry) {
            $bracket = $this->find((string) ($entry['token'] ?? ''));
            if ($bracket !== null && (bool) ($bracket['is_template'] ?? false)) {
                return $bracket;
            }
        }

        return null;
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
