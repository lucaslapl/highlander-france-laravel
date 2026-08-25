<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;
use App\Services\JsonClient;
use Illuminate\Support\Facades\DB;

/**
 * Backfill des équipes et scores de match manquants (app:backfill-match-teams).
 */
final class BackfillMatchTeamsService
{
    private const SCRIPT_NAME = 'backfill_match_teams.php';

    private \PDO $db;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    public function run(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        $missing = $this->db->query("
            SELECT DISTINCT pm.match_id
            FROM player_matches pm
            LEFT JOIN match_scores ms ON ms.match_id = pm.match_id
            WHERE pm.team IS NULL OR ms.match_id IS NULL
        ")->fetchAll(\PDO::FETCH_COLUMN);

        if ($missing === []) {
            AdminLogger::log(self::SCRIPT_NAME, $logToken, 'SUCCESS (rien à backfiller)');

            return 'Aucun log à backfiller.';
        }

        $updatedPlayers = 0;
        $updatedScores = 0;
        $failed = 0;

        $stmtTeam = $this->db->prepare('UPDATE player_matches SET team = ? WHERE steamid = ? AND match_id = ?');
        $stmtScore = $this->db->prepare('INSERT INTO match_scores (match_id, red_score, blue_score)
                                         VALUES (?, ?, ?)
                                         ON DUPLICATE KEY UPDATE
                                             red_score = VALUES(red_score),
                                             blue_score = VALUES(blue_score)');

        foreach ($missing as $matchId) {
            $matchId = (int) $matchId;

            $details = JsonClient::get('https://logs.tf/api/v1/log/' . $matchId);
            if ($details === null || !isset($details['players'])) {
                $failed++;
                continue;
            }

            // Équipe par joueur.
            foreach ($details['players'] as $steamid => $pData) {
                $team = strtolower((string) ($pData['team'] ?? ''));
                if (!in_array($team, ['red', 'blue'], true)) {
                    continue;
                }
                $stmtTeam->execute([$team, $steamid, $matchId]);
                $updatedPlayers += $stmtTeam->rowCount();
            }

            // Scores RED / BLU.
            $redScore = (int) ($details['teams']['Red']['score'] ?? 0);
            $blueScore = (int) ($details['teams']['Blue']['score'] ?? 0);
            $stmtScore->execute([$matchId, $redScore, $blueScore]);
            $updatedScores++;

            usleep(300000);
        }

        AdminLogger::log(
            self::SCRIPT_NAME,
            $logToken,
            "SUCCESS ($updatedPlayers équipes joueurs, $updatedScores scores sur " . count($missing) . ' logs, ' . $failed . ' échecs)',
        );

        return "Backfill terminé : $updatedPlayers équipes joueurs et $updatedScores scores mis à jour sur " . count($missing) . " logs ($failed échecs).";
    }
}
