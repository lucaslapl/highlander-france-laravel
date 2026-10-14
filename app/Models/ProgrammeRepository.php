<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Persistance des overlays « Programme » (outil admin « Overlay
 * Programme ») : écran d'attente / de présentation pour les broadcasts
 * OBS — horloge, décompte avant le match, équipes du jour, bandeau de
 * bienvenue avec diaporama de messages et programme de la semaine.
 *
 * Même modèle que PickBanRepository : caches JSON sous
 * hlfr_data_path('programmes/') — un index (liste des overlays) et un
 * payload complet par overlay. Chaque overlay possède son token
 * aléatoire (16 caractères) et son URL publique
 * (/programme-overlay/{token}) à pointer dans OBS via une source
 * navigateur web ; la version est bumpée à chaque enregistrement (ou
 * ajustement du décompte) pour le polling de rafraîchissement de la
 * vue overlay.
 *
 * Aucun modèle Eloquent : état 100 % fichiers, régénérable à la main.
 */
final class ProgrammeRepository
{
    private string $dir;

    public function __construct()
    {
        $this->dir = hlfr_data_path('programmes');
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
     * Persiste un overlay (payload + entrée d'index) en bumpant sa
     * version, que la vue overlay interroge pour se rafraîchir dans OBS.
     * Retourne false si l'écriture échoue (répertoire absent / non
     * inscriptible, JSON invalide) : l'appelant doit le signaler —
     * sinon l'outil admin affiche « enregistré » alors que rien n'a
     * été écrit et que le formulaire revient à son état d'avant.
     *
     * @param  array<string, mixed>  $overlay
     */
    public function save(array $overlay): bool
    {
        $token = (string) ($overlay['token'] ?? '');
        if ($token === '') {
            return false;
        }

        $overlay['version'] = time();
        $overlay['updated_at'] = time();

        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        $json = json_encode($overlay);
        if ($json === false || @file_put_contents($this->payloadFile($token), $json, LOCK_EX) === false) {
            return false;
        }

        $index = $this->readIndex();
        $entry = [
            'token' => $token,
            'name' => (string) ($overlay['name'] ?? $token),
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

        return $this->writeIndex($index);
    }

    /**
     * Supprime un overlay : payload et entrée d'index. Retourne false
     * si l'écriture de l'index échoue.
     */
    public function delete(string $token): bool
    {
        if (! preg_match('/^[a-z0-9]{16}$/', $token)) {
            return false;
        }

        $payloadFile = $this->payloadFile($token);
        if (is_file($payloadFile) && ! @unlink($payloadFile)) {
            return false;
        }

        $index = array_values(array_filter(
            $this->readIndex(),
            static fn (array $entry): bool => ($entry['token'] ?? '') !== $token
        ));

        return $this->writeIndex($index);
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
    private function writeIndex(array $index): bool
    {
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        return @file_put_contents($this->dir.'/index.json', json_encode(array_values($index)), LOCK_EX) !== false;
    }

    private function payloadFile(string $token): string
    {
        return $this->dir.'/'.$token.'.json';
    }
}
