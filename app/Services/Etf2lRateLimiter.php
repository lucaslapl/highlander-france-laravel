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
 * Ici, l'horodatage du dernier appel réel vit dans un fichier du
 * répertoire de données protégé par flock() : chaque appel attend que
 * le délai minimal depuis le dernier appel — quel que soit le process
 * émetteur — soit écoulé, puis enregistre le sien.
 */
final class Etf2lRateLimiter
{
    /** Délai minimal (s) entre deux appels réels, tous processus confondus (~50 req/min). */
    public const DEFAULT_DELAY_S = 1.2;

    /** Fichier d'horodatage du dernier appel (répertoire de données hlfr). */
    private const STATE_FILE = 'etf2l_rate_limit.lock';

    /**
     * Délai explicite (0.0 = espacement désactivé, ex. tests avec fetcher
     * factice) ; null = délai par défaut (désactivé en tests, 1,2 s en prod).
     */
    public function __construct(private readonly ?float $delayS = null) {}

    /**
     * Bloque jusqu'à ce que le délai minimal depuis le dernier appel réel
     * (tous processus confondus) soit écoulé, puis enregistre l'appel.
     */
    public function wait(): void
    {
        $delay = $this->delayS ?? (app()->runningUnitTests() ? 0.0 : self::DEFAULT_DELAY_S);

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
            rewind($state);
            $last = (float) (string) stream_get_contents($state);

            $elapsed = microtime(true) - $last;
            if ($last > 0.0 && $elapsed < $delay) {
                usleep((int) (($delay - $elapsed) * 1e6));
            }

            ftruncate($state, 0);
            rewind($state);
            fwrite($state, (string) microtime(true));
        } finally {
            flock($state, LOCK_UN);
            fclose($state);
        }
    }
}
