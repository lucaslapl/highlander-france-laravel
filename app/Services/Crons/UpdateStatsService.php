<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Models\MatchLogRepository;
use App\Models\MatchStatsRepository;
use App\Models\OfficialMatchRepository;
use App\Services\AdminLogger;
use App\Services\JsonClient;
use App\Services\OfficialLogProcessor;
use Illuminate\Support\Facades\DB;

/**
 * Mise à jour des stats de match pour les joueurs (app:update-stats).
 */
final class UpdateStatsService
{
    private const SCRIPT_NAME = 'update_stats.php';

    /** Verrou anti-concurrence : une seule exécution à la fois (cron + webhook + panel admin). */
    private const LOCK_FILE = 'update_stats.lock';

    private const LOGS_TF_URLS = [
        'https://logs.tf/api/v1/log?title=Highlander%20France',
        'https://logs.tf/api/v1/log?title=highlanderfrance.tf',
    ];

    private \PDO $db;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    public function run(): string
    {
        $lock = fopen(hlfr_data_path(self::LOCK_FILE), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            AdminLogger::skipped(self::SCRIPT_NAME, 'Tâche ignorée : une autre exécution est déjà en cours.');

            return 'Mise à jour des stats ignorée : une autre exécution est déjà en cours.';
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

        $repo = new MatchStatsRepository;
        $blacklistRepo = new MatchLogRepository;
        $officialRepo = new OfficialMatchRepository;
        $processor = new OfficialLogProcessor($repo, $blacklistRepo);

        $dataOld = JsonClient::get(self::LOGS_TF_URLS[0]);
        $dataNew = JsonClient::get(self::LOGS_TF_URLS[1]);

        if ($dataOld === null && $dataNew === null) {
            throw new \RuntimeException("Impossible de récupérer l'index initial sur logs.tf");
        }

        $allLogs = [];
        foreach (array_merge($dataOld['logs'] ?? [], $dataNew['logs'] ?? []) as $log) {
            if (isset($log['id'])) {
                $allLogs[$log['id']] = $log;
            }
        }

        // Cache des dates logs.tf (utilisé par les graphiques du dashboard admin).
        foreach ($allLogs as $log) {
            $repo->saveLogDate((int) $log['id'], (int) ($log['date'] ?? 0));
        }

        // Purge rétroactive : les logs blacklistés déjà traités sont retirés des stats joueurs.
        $blacklistedIds = $blacklistRepo->blacklistedIds();
        $purgedCount = $repo->purgeBlacklisted($blacklistedIds);

        // Purge rétroactive : les logs sans classe (undefined/unknown) sont retirés des stats joueurs.
        $purgedClassCount = $repo->purgeInvalidClasses();

        $processedCount = 0;

        foreach ($allLogs as $log) {
            $logId = (int) $log['id'];

            if (in_array($logId, $blacklistedIds, true)) {
                continue;
            }

            if ($repo->isProcessed($logId)) {
                continue;
            }

            // Un log officiel de ligue prime sur le sniffing de titre : sa
            // catégorie vient de la compétition ETF2L, pas de "[6s]" dans le titre.
            $officialCategory = $officialRepo->categoryFor($logId);
            if ($officialCategory !== null) {
                $gameMode = $officialCategory;
            } else {
                $title = strtolower((string) ($log['title'] ?? ''));
                $gameMode = str_contains($title, '[6s]') ? '6s' : '9v9';
            }

            if ($processor->process($logId, $gameMode)) {
                $processedCount++;
            }
        }

        // Logs officiels de ligue (rattachés depuis la page Stats équipes ou le
        // CRON app:sync-* retiré : ne restent que ceux saisis/scrapés sur la page) :
        // parfois absents des recherches par titre, on les traite par ID en forçant
        // la catégorie de la compétition.
        $officialProcessed = 0;
        foreach ($officialRepo->pendingLogIds() as $logId => $category) {
            $info = $officialRepo->infoFor((int) $logId);
            $expectedDate = $info['match_time'] ?? null;

            if ($processor->process((int) $logId, $category, $expectedDate !== null ? (int) $expectedDate : null)) {
                $officialProcessed++;
            }

            usleep(200000);
        }

        $statusMsg = 'SUCCESS ('.$processedCount.' nouveaux logs traités, '
            .$officialProcessed.' logs officiels traités, '
            .$purgedClassCount.' logs sans classe purgés)';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        file_put_contents(hlfr_data_path('log_update_stats.txt'), date('Y-m-d H:i:s')." OK\n", FILE_APPEND);

        return 'Mise à jour des stats terminée. Nouveaux logs traités : '.$processedCount
            .' ('.$officialProcessed.' officiels). Logs sans classe purgés : '.$purgedClassCount;
    }
}
