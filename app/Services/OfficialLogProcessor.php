<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MatchLogRepository;
use App\Models\MatchStatsRepository;
use App\Services\JsonClient;
use App\Services\LogParser;

/**
 * Téléchargement et traitement d'un log logs.tf unique : insertion des scores,
 * de la date et des stats par joueur (partagé par le CRON app:update-stats et
 * le recalcul depuis la page de stats multi-équipes).
 */
final class OfficialLogProcessor
{
    private MatchStatsRepository $repo;

    private MatchLogRepository $blacklistRepo;

    public function __construct(?MatchStatsRepository $repo = null, ?MatchLogRepository $blacklistRepo = null)
    {
        $this->repo = $repo ?? new MatchStatsRepository;
        $this->blacklistRepo = $blacklistRepo ?? new MatchLogRepository;
    }

    /**
     * Traite un log logs.tf (insertion scores + stats joueurs). Retourne true
     * si le log a été traité, false s'il était déjà traité ou a été rejeté.
     */
    public function process(int $logId, string $gameMode, ?int $fallbackDate = null): bool
    {
        if ($this->repo->isProcessed($logId)) {
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
            $this->blacklistRepo->blacklist($logId, 'Durée inférieure à 5 minutes (blacklist automatique)', 'auto');
            $this->repo->markProcessed($logId);

            return false;
        }

        $rawMap = (string) ($details['info']['map'] ?? 'unknown');
        $mapName = preg_replace('/_(v|rc|f)\d+.*?$/i', '', $rawMap) ?? 'unknown';

        $perLogStats = LogParser::extract($details);

        // Scores RED / BLU (page détail d'un log).
        $redScore = (int) ($details['teams']['Red']['score'] ?? 0);
        $blueScore = (int) ($details['teams']['Blue']['score'] ?? 0);
        $this->repo->saveMatchScores($logId, $redScore, $blueScore);

        $date = (int) ($details['info']['date'] ?? $details['date'] ?? 0);
        if ($date <= 0 && $fallbackDate !== null) {
            $date = $fallbackDate;
        }
        if ($date > 0) {
            $this->repo->saveLogDate($logId, $date);
        }

        if (isset($details['players'])) {
            foreach ($details['players'] as $steamid => $pData) {
                $steamid = (string) $steamid;

                $this->repo->incrementPlayerStat($steamid, $gameMode);

                $classPlayed = 'unknown';
                if (! empty($pData['class_stats']) && isset($pData['class_stats'][0]['type'])) {
                    $classPlayed = (string) $pData['class_stats'][0]['type'];
                }

                $stats = $perLogStats[$steamid] ?? [];
                $this->repo->upsertPlayerMatch($steamid, $logId, $mapName, $classPlayed, $gameMode, $stats);

                // Nouveau joueur inconnu en base : on synchronise son profil Steam.
                if (! $this->repo->playerExists($steamid)) {
                    $steamUrl = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key='.(string) config('hlfr.steam_api_key', '').'&steamids='.$steamid;
                    $sData = JsonClient::get($steamUrl);

                    if (isset($sData['response']['players'][0])) {
                        $p = $sData['response']['players'][0];
                        $this->repo->insertPlayer($steamid, (string) ($p['personaname'] ?? ''), (string) ($p['avatarfull'] ?? ''));
                    }

                    usleep(500000);
                }
            }
        }

        $this->repo->markProcessed($logId);

        return true;
    }
}
