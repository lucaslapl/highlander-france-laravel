<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;
use App\Services\OfficialLogsService;
use Illuminate\Support\Facades\DB;

/**
 * Récupération automatique des logs.tf des matchs officiels ETF2L (app:sync-etf2l-logs).
 *
 * Pour chaque match terminé sans log rattaché, scrape la page ETF2L et rattache
 * les logs trouvés (source auto). Les matchs dont le scrape ne renvoie rien sont
 * marqués (logs_checked_at) pour ne pas être retentés inutilement.
 */
final class SyncEtf2lLogsService
{
    private const SCRIPT_NAME = 'sync_etf2l_logs.php';

    private const LOCK_FILE = 'sync_etf2l_logs.lock';

    /** Nombre maximal de pages ETF2L scrapées par exécution. */
    private const MAX_PER_RUN = 20;

    /** Ne pas re-vérifier un match sans log avant ce délai (secondes). */
    private const RETRY_DELAY_S = 86400;

    public function run(): string
    {
        $lock = fopen(hlfr_data_path(self::LOCK_FILE), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            AdminLogger::skipped(self::SCRIPT_NAME, 'Tâche ignorée : une autre exécution est déjà en cours.');

            return 'Récupération des logs officiels ignorée : une autre exécution est déjà en cours.';
        }

        try {
            return $this->doRun();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function doRun(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        $matches = DB::table('etf2l_matches')
            ->whereNotNull('r1')
            ->whereNotNull('r2')
            ->where(function ($q): void {
                $q->whereNull('logs_checked_at')
                    ->orWhere('logs_checked_at', '<', time() - self::RETRY_DELAY_S);
            })
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('official_logs')
                    ->whereColumn('official_logs.etf2l_match_id', 'etf2l_matches.match_id');
            })
            ->orderByDesc('match_date')
            ->limit(self::MAX_PER_RUN)
            ->get();

        $service = new OfficialLogsService;
        $scraped = 0;
        $attached = 0;

        foreach ($matches as $match) {
            $result = $service->scrapeMatch((int) $match->match_id);
            $scraped++;
            $attached += $result['attached'];
        }

        $statusMsg = 'SUCCESS ('.$scraped.' match(s) scrapé(s), '.$attached.' log(s) officiel(s) rattaché(s))';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        return 'Logs officiels : '.$scraped.' match(s) ETTF2L vérifié(s), '.$attached.' log(s) rattaché(s).';
    }
}
