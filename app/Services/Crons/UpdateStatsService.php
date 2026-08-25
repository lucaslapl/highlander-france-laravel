<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Models\MatchLogRepository;
use App\Models\MatchStatsRepository;
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
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        $repo = new MatchStatsRepository();
        $blacklistRepo = new MatchLogRepository();

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

        $minMatchLength = (int) config('hlfr.min_match_length', 300);
        $processedCount = 0;

        foreach ($allLogs as $log) {
            $logId = (int) $log['id'];

            if (in_array($logId, $blacklistedIds, true)) {
                continue;
            }

            $title = (string) ($log['title'] ?? '');

            if ($repo->isProcessed($logId)) {
                continue;
            }

            $details = JsonClient::get('https://logs.tf/api/v1/log/' . $logId);
            if ($details === null) {
                error_log('Erreur API logs.tf pour le log ' . $logId);
                continue;
            }

            // Auto-blacklist : un log de moins de 5 minutes est exclu de toutes les stats.
            $logLength = (int) ($details['length'] ?? 0);
            if ($logLength > 0 && $logLength < $minMatchLength) {
                $blacklistRepo->blacklist($logId, 'Durée inférieure à 5 minutes (blacklist automatique)', 'auto');
                $repo->markProcessed($logId);
                continue;
            }

            // Mode de jeu (6s / 9v9) d'après le titre du log.
            $titleLower = strtolower($title);
            if (str_contains($titleLower, '[6s]')) {
                $gameMode = '6s';
            } else {
                $gameMode = '9v9';
            }

            $rawMap = (string) ($details['info']['map'] ?? 'unknown');
            $mapName = preg_replace('/_(v|rc|f)\d+.*?$/i', '', $rawMap) ?? 'unknown';

            $perLogStats = LogParser::extract($details);

            // Scores RED / BLU (page détail d'un log).
            $redScore = (int) ($details['teams']['Red']['score'] ?? 0);
            $blueScore = (int) ($details['teams']['Blue']['score'] ?? 0);
            $repo->saveMatchScores($logId, $redScore, $blueScore);

            if (isset($details['players'])) {
                foreach ($details['players'] as $steamid => $pData) {
                    $steamid = (string) $steamid;

                    $repo->incrementPlayerStat($steamid, $gameMode);

                    $classPlayed = 'unknown';
                    if (!empty($pData['class_stats']) && isset($pData['class_stats'][0]['type'])) {
                        $classPlayed = (string) $pData['class_stats'][0]['type'];
                    }

                    $stats = $perLogStats[$steamid] ?? [];
                    $repo->upsertPlayerMatch($steamid, $logId, $mapName, $classPlayed, $gameMode, $stats);

                    // Nouveau joueur inconnu en base : on synchronise son profil Steam.
                    if (!$repo->playerExists($steamid)) {
                        $steamUrl = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=' . (string) env('STEAM_API_KEY', '') . '&steamids=' . $steamid;
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
            $processedCount++;
            usleep(200000);
        }

        $statusMsg = 'SUCCESS (' . $processedCount . ' nouveaux logs traités, '
            . $purgedClassCount . ' logs sans classe purgés)';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        file_put_contents(hlfr_data_path('log_update_stats.txt'), date('Y-m-d H:i:s') . " OK\n", FILE_APPEND);

        return 'Mise à jour des stats terminée. Nouveaux logs traités : ' . $processedCount
            . '. Logs sans classe purgés : ' . $purgedClassCount;
    }
}
