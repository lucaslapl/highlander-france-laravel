<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Models\MatchLogRepository;
use App\Models\MatchStatsRepository;
use App\Models\OfficialLogsRepository;
use App\Services\AdminLogger;
use App\Services\JsonClient;
use App\Services\LogParser;
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
        $officialRepo = new OfficialLogsRepository;

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

            if ($this->processLog($repo, $logId, $gameMode)) {
                $processedCount++;
            }
        }

        // Logs officiels de ligue (rattachés via /admin/ligue-logs ou le CRON
        // app:sync-etf2l-logs) : parfois absents des recherches par titre, on les
        // traite par ID en forçant la catégorie de la compétition.
        $officialProcessed = 0;
        foreach ($officialRepo->pendingLogIds() as $logId => $category) {
            $info = $officialRepo->infoFor((int) $logId);
            $expectedDate = $info['etf2l_match_id'] !== null
                ? (int) DB::table('etf2l_matches')->where('match_id', $info['etf2l_match_id'])->value('match_date')
                : 0;

            if ($this->processLog($repo, (int) $logId, $category, $expectedDate > 0 ? $expectedDate : null)) {
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

    /**
     * Télécharge et traite un seul log logs.tf (insertion scores + stats joueurs).
     *
     * @return bool true si le log a été traité (faux si déjà blacklisté / ignoré).
     */
    private function processLog(MatchStatsRepository $repo, int $logId, string $gameMode, ?int $fallbackDate = null): bool
    {
        if ($repo->isProcessed($logId)) {
            return false;
        }

        $details = JsonClient::get('https://logs.tf/api/v1/log/'.$logId);
        if ($details === null) {
            error_log('Erreur API logs.tf pour le log '.$logId);

            return false;
        }

        // Auto-blacklist : un log de moins de 5 minutes est exclu de toutes les stats.
        $logLength = (int) ($details['length'] ?? 0);
        $minMatchLength = (int) config('hlfr.min_match_length', 300);
        if ($logLength > 0 && $logLength < $minMatchLength) {
            (new MatchLogRepository)->blacklist($logId, 'Durée inférieure à 5 minutes (blacklist automatique)', 'auto');
            $repo->markProcessed($logId);

            return false;
        }

        $rawMap = (string) ($details['info']['map'] ?? 'unknown');
        $mapName = preg_replace('/_(v|rc|f)\d+.*?$/i', '', $rawMap) ?? 'unknown';

        $perLogStats = LogParser::extract($details);

        // Scores RED / BLU (page détail d'un log).
        $redScore = (int) ($details['teams']['Red']['score'] ?? 0);
        $blueScore = (int) ($details['teams']['Blue']['score'] ?? 0);
        $repo->saveMatchScores($logId, $redScore, $blueScore);

        $date = (int) ($details['info']['date'] ?? $details['date'] ?? 0);
        if ($date <= 0 && $fallbackDate !== null) {
            $date = $fallbackDate;
        }
        if ($date > 0) {
            $repo->saveLogDate($logId, $date);
        }

        if (isset($details['players'])) {
            foreach ($details['players'] as $steamid => $pData) {
                $steamid = (string) $steamid;

                $repo->incrementPlayerStat($steamid, $gameMode);

                $classPlayed = 'unknown';
                if (! empty($pData['class_stats']) && isset($pData['class_stats'][0]['type'])) {
                    $classPlayed = (string) $pData['class_stats'][0]['type'];
                }

                $stats = $perLogStats[$steamid] ?? [];
                $repo->upsertPlayerMatch($steamid, $logId, $mapName, $classPlayed, $gameMode, $stats);

                // Nouveau joueur inconnu en base : on synchronise son profil Steam.
                if (! $repo->playerExists($steamid)) {
                    $steamUrl = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key='.(string) config('hlfr.steam_api_key', '').'&steamids='.$steamid;
                    $sData = JsonClient::get($steamUrl);

                    if (isset($sData['response']['players'][0])) {
                        $p = $sData['response']['players'][0];
                        $repo->insertPlayer($steamid, (string) ($p['personaname'] ?? ''), (string) ($p['avatarfull'] ?? ''));
                    }

                    usleep(500000);
                }
            }
        }

        $repo->markProcessed($logId);

        return true;
    }
}
