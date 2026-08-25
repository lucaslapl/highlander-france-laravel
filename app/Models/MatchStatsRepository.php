<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Opérations base de données partagées par les scripts CRON
 * (stats des matchs joueurs, purges, cache des dates).
 */
final class MatchStatsRepository
{
    // --- Logs (processed_logs) ---

    public function isProcessed(int $logId): bool
    {
        return DB::table('processed_logs')->where('id', $logId)->exists();
    }

    public function markProcessed(int $logId): void
    {
        DB::table('processed_logs')->insertOrIgnore(['id' => $logId]);
    }

    // --- Cache des dates (log_dates) ---

    public function saveLogDate(int $logId, int $date): void
    {
        DB::table('log_dates')->insertOrIgnore(['log_id' => $logId, 'date' => $date]);
    }

    // --- Scores (match_scores) ---

    public function saveMatchScores(int $matchId, int $redScore, int $blueScore): void
    {
        DB::table('match_scores')->upsert(
            ['match_id' => $matchId, 'red_score' => $redScore, 'blue_score' => $blueScore],
            ['match_id'],
            ['red_score', 'blue_score'],
        );
    }

    // --- Compteurs (player_stats) ---

    public function incrementPlayerStat(string $steamid, string $gameMode): void
    {
        DB::table('player_stats')->upsert(
            ['steamid' => $steamid, 'count' => 1, 'game_mode' => $gameMode],
            ['steamid', 'game_mode'],
            [DB::raw('count = count + 1')],
        );
    }

    // --- Joueurs (players_info) ---

    public function playerExists(string $steamid): bool
    {
        return DB::table('players_info')->where('steamid', $steamid)->exists();
    }

    public function insertPlayer(string $steamid, string $name, string $avatar): void
    {
        DB::table('players_info')->insert([
            'steamid' => $steamid,
            'name' => $name,
            'avatar' => $avatar,
            'last_updated' => time(),
        ]);
    }

    // --- Détail d'un match joueur (player_matches) ---

    /**
     * Insère ou met à jour la ligne player_matches d'un joueur pour un log.
     * Ne modifie JAMAIS map_name/class_played/game_mode sur un conflit
     * (pour préserver les corrections manuelles des admins).
     *
     * @param array<string, mixed> $stats
     */
    public function upsertPlayerMatch(string $steamid, int $matchId, string $mapName, string $classPlayed, string $gameMode, array $stats): void
    {
        $values = [
            'steamid' => $steamid,
            'match_id' => $matchId,
            'map_name' => $mapName,
            'class_played' => $classPlayed,
            'game_mode' => $gameMode,
            'dmg' => (int) ($stats['dmg'] ?? 0),
            'kills' => (int) ($stats['kills'] ?? 0),
            'deaths' => (int) ($stats['deaths'] ?? 0),
            'assists' => (int) ($stats['assists'] ?? 0),
            'suicides' => (int) ($stats['suicides'] ?? 0),
            'heal' => (int) ($stats['heal'] ?? 0),
            'medkits' => (int) ($stats['medkits'] ?? 0),
            'ubers' => (int) ($stats['ubers'] ?? 0),
            'drops' => (int) ($stats['drops'] ?? 0),
            'backstabs' => (int) ($stats['backstabs'] ?? 0),
            'headshots' => (int) ($stats['headshots'] ?? 0),
            'longest_killstreak' => (int) ($stats['longest_killstreak'] ?? 0),
            'classes_killed' => (string) ($stats['classes_killed'] ?? '[]'),
            'length' => (int) ($stats['length'] ?? 0),
            'dapm' => (int) ($stats['dapm'] ?? 0),
            'dmg_taken' => (int) ($stats['dmg_taken'] ?? 0),
            'medkits_hp' => (int) ($stats['medkits_hp'] ?? 0),
            'airshots' => (int) ($stats['airshots'] ?? 0),
            'captures' => (int) ($stats['captures'] ?? 0),
            'won' => array_key_exists('won', $stats) ? (is_null($stats['won']) ? null : (int) $stats['won']) : null,
            'team' => (isset($stats['team']) && in_array($stats['team'], ['red', 'blue'], true)) ? $stats['team'] : null,
        ];

        $updateColumns = [
            'dmg', 'kills', 'deaths', 'assists', 'suicides', 'heal', 'medkits',
            'ubers', 'drops', 'backstabs', 'headshots', 'longest_killstreak',
            'classes_killed', 'length', 'dapm', 'dmg_taken', 'medkits_hp',
            'airshots', 'captures', 'won', 'team',
        ];

        DB::table('player_matches')->upsert([$values], ['steamid', 'match_id'], $updateColumns);
    }

    // --- Purges rétroactives ---

    /**
     * Retire des stats les logs blacklistés déjà traités.
     *
     * @param int[] $blacklistedIds
     */
    public function purgeBlacklisted(array $blacklistedIds): int
    {
        if ($blacklistedIds === []) {
            return 0;
        }

        $rows = DB::table('player_matches')
            ->whereIn('match_id', $blacklistedIds)
            ->select('steamid', 'game_mode')
            ->get();

        foreach ($rows as $m) {
            DB::table('player_stats')
                ->where('steamid', $m->steamid)
                ->where('game_mode', $m->game_mode)
                ->decrement('count');
        }
        DB::table('player_stats')->where('count', '<=', 0)->delete();

        return DB::table('player_matches')->whereIn('match_id', $blacklistedIds)->delete();
    }

    /**
     * Retire des stats les matchs sans classe (undefined/unknown).
     */
    public function purgeInvalidClasses(): int
    {
        $rows = DB::table('player_matches')
            ->select('steamid', 'game_mode', DB::raw('COUNT(*) AS cnt'))
            ->whereIn('class_played', ['undefined', 'unknown'])
            ->groupBy('steamid', 'game_mode')
            ->get();

        $purged = 0;
        if ($rows->isNotEmpty()) {
            foreach ($rows as $row) {
                DB::table('player_stats')
                    ->where('steamid', $row->steamid)
                    ->where('game_mode', $row->game_mode)
                    ->decrement('count', (int) $row->cnt);
            }

            $purged = DB::table('player_matches')
                ->whereIn('class_played', ['undefined', 'unknown'])
                ->delete();

            DB::table('player_stats')->where('count', '<=', 0)->delete();
        }

        return $purged;
    }
}
