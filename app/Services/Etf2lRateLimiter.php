<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Espacement global des appels HTTP vers l'API ETF2L v2 (rate-limit :
 * 60 requêtes/minute par IP, tous les scripts du site confondus).
 *
 * Les services historiques gardaient chacun un horodatage en mémoire :
 * le délai ne s'appliquait qu'au sein d'une même requête PHP, jamais
 * entre deux processus PHP-FPM (cron de synchro, remplissage assisté
 * des overlays, résolution de pseudos, statut du dashboard…), et des
 * rafales concurrentes déclenchaient le throttle 429 de l'API.
 *
 * Ici, l'état vit dans un fichier du répertoire de données protégé par
 * flock() : chaque appel attend que le délai minimal depuis le dernier
 * appel — quel que soit le process émetteur — soit écoulé, puis
 * enregistre le sien.
 *
 * L'espacement est de plus adaptatif : chaque réponse de l'API annonce
 * le budget restant de la minute courante (en-têtes x-ratelimit-remaining
 * / x-ratelimit-limit) ; JsonClient les remonte via reportFromHeaders()
 * et les prochains wait() marquent une pause supplémentaire quand le
 * budget est bas — l'API pouvant resserrer sa limite à la volée, mieux
 * vaut voler sur son compteur que sur un délai fixé une bonne fois.
 */
final class Etf2lRateLimiter
{
    /** Délai minimal (s) entre deux appels réels, tous processus confondus (~30 req/min). */
    public const DEFAULT_DELAY_S = 2.0;

    /** Fichier d'état du dernier appel et du budget annoncé (répertoire de données hlfr). */
    private const STATE_FILE = 'etf2l_rate_limit.lock';

    /** Budget restant annoncé sous lequel on ralentit nettement. */
    private const LOW_BUDGET = 10;

    /** Budget restant annoncé sous lequel on stoppe quasiment (fenêtre bientôt épuisée). */
    private const CRITICAL_BUDGET = 3;

    /** Pause modérée, en multiples du délai de base (2 s => 5 s). */
    private const LOW_BUDGET_MULTIPLIER = 2.5;

    /** Pause critique, en multiples du délai de base (2 s => 30 s). */
    private const CRITICAL_BUDGET_MULTIPLIER = 15.0;

    /** Péremption du budget annoncé (s) : au-delà, la fenêtre d'une minute s'est rechargée. */
    private const BUDGET_STALENESS_S = 70.0;

    /**
     * Délai explicite (0.0 = espacement désactivé, ex. tests avec fetcher
     * factice) ; null = délai par défaut (désactivé en tests, 2 s en prod).
     */
    public function __construct(private readonly ?float $delayS = null) {}

    /**
     * Bloque jusqu'à ce que le délai minimal depuis le dernier appel réel
     * (tous processus confondus) soit écoulé — pause supplémentaire si le
     * budget annoncé par l'API est bas — puis enregistre l'appel.
     */
    public function wait(): void
    {
        $delay = $this->baseDelay();

        if ($delay <= 0.0) {
            return;
        }

        $state = fopen(hlfr_data_path(self::STATE_FILE), 'c+');
        if ($state === false) {
            // Fichier d'état inutilisable : on retombe sur un délai local.
            usleep((int) ($delay * 1e6));

            return;
        }

        flock($state, LOCK_EX);
        try {
            $data = $this->readState($state);
            $now = microtime(true);
            $last = (float) ($data['last'] ?? 0.0);

            // Espacement de base + pause adaptative liée au budget restant.
            $effectiveDelay = $delay + $this->budgetPauseS($data, $now, $delay);

            $elapsed = $now - $last;
            if ($last > 0.0 && $elapsed < $effectiveDelay) {
                usleep((int) (($effectiveDelay - $elapsed) * 1e6));
            }

            $data['last'] = microtime(true);
            $this->writeState($state, $data);
        } finally {
            flock($state, LOCK_UN);
            fclose($state);
        }
    }

    /**
     * Enregistre le budget de la minute annoncé par l'API dans les en-têtes
     * de réponse (x-ratelimit-remaining / x-ratelimit-limit) : les prochains
     * wait() adapteront leur pause au quota réellement restant. Appelé par
     * JsonClient après chaque réponse ETF2L.
     *
     * @param  array<string, string>  $headers  en-têtes de réponse (noms en minuscules)
     */
    public function reportFromHeaders(array $headers): void
    {
        $remaining = $headers['x-ratelimit-remaining'] ?? null;

        if ($remaining === null) {
            return;
        }

        $state = fopen(hlfr_data_path(self::STATE_FILE), 'c+');
        if ($state === false) {
            return;
        }

        flock($state, LOCK_EX);
        try {
            $data = $this->readState($state);
            $data['remaining'] = (int) $remaining;
            $data['limit'] = isset($headers['x-ratelimit-limit'])
                ? (int) $headers['x-ratelimit-limit']
                : ($data['limit'] ?? null);
            $data['budget_at'] = microtime(true);
            $this->writeState($state, $data);
        } finally {
            flock($state, LOCK_UN);
            fclose($state);
        }
    }

    private function baseDelay(): float
    {
        return $this->delayS ?? (app()->runningUnitTests() ? 0.0 : self::DEFAULT_DELAY_S);
    }

    /**
     * Pause supplémentaire (s) selon le budget restant annoncé : nulle si le
     * budget est inconnu ou périmé (la fenêtre d'une minute s'est rechargée
     * depuis la dernière réponse).
     *
     * @param  array<string, mixed>  $data
     */
    private function budgetPauseS(array $data, float $now, float $baseDelay): float
    {
        $remaining = $data['remaining'] ?? null;

        if (! is_int($remaining)) {
            return 0.0;
        }

        if ($now - (float) ($data['budget_at'] ?? 0.0) > self::BUDGET_STALENESS_S) {
            return 0.0;
        }

        if ($remaining <= self::CRITICAL_BUDGET) {
            return self::CRITICAL_BUDGET_MULTIPLIER * $baseDelay;
        }

        if ($remaining <= self::LOW_BUDGET) {
            return self::LOW_BUDGET_MULTIPLIER * $baseDelay;
        }

        return 0.0;
    }

    /**
     * Lit l'état partagé (JSON), avec tolérance pour l'ancien format
     * (horodatage brut seul, sans budget).
     *
     * @param  resource  $stream
     * @return array<string, mixed>
     */
    private function readState($stream): array
    {
        rewind($stream);
        $raw = (string) stream_get_contents($stream);
        $decoded = json_decode($raw, true);

        if (is_array($decoded) && isset($decoded['last'])) {
            return $decoded;
        }

        $last = (float) $raw;

        return ['last' => $last > 0.0 ? $last : 0.0];
    }

    /**
     * Écrase l'état partagé par la représentation JSON fournie.
     *
     * @param  resource  $stream
     * @param  array<string, mixed>  $data
     */
    private function writeState($stream, array $data): void
    {
        ftruncate($stream, 0);
        rewind($stream);
        fwrite($stream, (string) json_encode($data));
    }
}
