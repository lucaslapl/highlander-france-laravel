<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Models\MatchStatsRepository;
use App\Services\AdminLogger;
use App\Services\JsonClient;
use App\Services\LogParser;
use Illuminate\Support\Facades\DB;

/**
 * Backfill des stats de match joueurs manquantes (app:backfill-player-match-stats).
 */
final class BackfillPlayerMatchStatsService
{
    private const SCRIPT_NAME = 'backfill_player_match_stats.php';

    private \PDO $db;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    public function run(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        $missing = $this->db->query("
            SELECT DISTINCT match_id FROM player_matches
            WHERE length = 0 OR won IS NULL
        ")->fetchAll(\PDO::FETCH_COLUMN);

        if ($missing === []) {
            AdminLogger::log(self::SCRIPT_NAME, $logToken, 'SUCCESS (rien à backfiller)');

            return 'Aucun log à backfiller.';
        }

        $repo = new MatchStatsRepository();
        $updated = 0;

        $selectPlayers = $this->db->prepare('SELECT steamid, map_name, class_played, game_mode FROM player_matches WHERE match_id = ?');

        foreach ($missing as $matchId) {
            $matchId = (int) $matchId;

            $details = JsonClient::get('https://logs.tf/api/v1/log/' . $matchId);
            if ($details === null || !isset($details['players'])) {
                continue;
            }

            // On relit les valeurs déjà en base (pour ne pas écraser les corrections admin).
            $selectPlayers->execute([$matchId]);
            $playersInLog = $selectPlayers->fetchAll(\PDO::FETCH_ASSOC);

            $perLogStats = LogParser::extract($details);

            foreach ($playersInLog as $row) {
                $repo->upsertPlayerMatch(
                    $row['steamid'],
                    $matchId,
                    (string) $row['map_name'],
                    (string) $row['class_played'],
                    (string) $row['game_mode'],
                    $perLogStats[$row['steamid']] ?? [],
                );
                $updated++;
            }
            usleep(300000);
        }

        AdminLogger::log(
            self::SCRIPT_NAME,
            $logToken,
            "SUCCESS ($updated stats joueurs mises à jour sur " . count($missing) . ' logs)',
        );

        return "Backfill terminé : $updated stats mises à jour sur " . count($missing) . ' logs.';
    }
}
