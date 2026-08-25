<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;

/**
 * Backfill ponctuel de l'historique ETF2L complet (app:backfill-etf2l-history).
 * Non programmé : à lancer à la main, par exemple après une première
 * installation ou une longue interruption du cron.
 */
final class BackfillEtf2lHistoryService
{
    private const SCRIPT_NAME = 'backfill_etf2l_history.php';

    /** Fenêtre d'historique maximale conservée sur le site (jours). */
    private const HISTORY_WINDOW_DAYS = 180;

    public function run(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        $message = (new SyncEtf2lService())->run(self::HISTORY_WINDOW_DAYS);

        AdminLogger::log(self::SCRIPT_NAME, $logToken, 'SUCCESS (backfill 180 jours)');

        return '[Backfill historique 180 jours] ' . $message;
    }
}
