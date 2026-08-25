<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

final class MatchLogRepository
{
    /**
     * IDs des logs logs.tf exclus des statistiques.
     *
     * @return int[]
     */
    public function blacklistedIds(): array
    {
        return array_map('intval', DB::table('log_blacklist')->pluck('log_id')->all());
    }

    /**
     * Ajoute un log à la blacklist (idempotent).
     *
     * @return bool true si le log vient d'être ajouté, false s'il y était déjà.
     */
    public function blacklist(int $logId, ?string $reason, string $addedBy): bool
    {
        $inserted = DB::table('log_blacklist')->insertOrIgnore([
            'log_id' => $logId,
            'reason' => $reason,
            'added_by' => $addedBy,
            'created_at' => now(),
        ]);

        return $inserted > 0;
    }

    /**
     * Retire un log de la blacklist.
     *
     * @return bool true si le log a été retiré, false s'il n'y était pas.
     */
    public function unblacklist(int $logId): bool
    {
        $deleted = DB::table('log_blacklist')->where('log_id', $logId)->delete();

        return $deleted > 0;
    }

    /**
     * Détail d'un match (page /log/{id}).
     *
     * @return array<string, mixed>|null null si le log n'a aucun joueur en base.
     */
    public function matchDetail(int $logId): ?array
    {
        $players = DB::table('player_matches as pm')
            ->leftJoin('players_info as pi', 'pi.steamid', '=', 'pm.steamid')
            ->where('pm.match_id', $logId)
            ->orderByDesc('pm.dmg')
            ->select(
                'pm.steamid', 'pm.map_name', 'pm.game_mode', 'pm.class_played', 'pm.team',
                'pm.dmg', 'pm.kills', 'pm.deaths', 'pm.assists',
                'pm.suicides', 'pm.heal', 'pm.medkits', 'pm.ubers', 'pm.drops', 'pm.backstabs',
                'pm.headshots', 'pm.longest_killstreak',
                'pi.name', 'pi.display_name', 'pi.avatar',
            )
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        if ($players === []) {
            return null;
        }

        $first = $players[0];

        $dateValue = DB::table('log_dates')->where('log_id', $logId)->value('date');
        $date = $dateValue !== null ? (int) $dateValue : null;

        $length = (int) DB::table('matches_cache')->where('match_id', $logId)->value('length');
        if ($length <= 0) {
            $length = (int) DB::table('log_length_cache')->where('log_id', $logId)->value('length');
        }

        $scoreRow = DB::table('match_scores')->where('match_id', $logId)->first();

        $redScore = null;
        $blueScore = null;
        if ($scoreRow !== null) {
            $redScore = (int) $scoreRow->red_score;
            $blueScore = (int) $scoreRow->blue_score;
        }

        return [
            'players' => $players,
            'map_name' => $first['map_name'] ?? '',
            'game_mode' => strtoupper($first['game_mode'] ?? '9v9'),
            'date' => $date,
            'length' => $length,
            'red_score' => $redScore,
            'blue_score' => $blueScore,
        ];
    }

    /**
     * Logs indexés (distincts, hors blacklist) pour le sitemap.
     *
     * @return array<int, array{id: int, date: int|null}>
     */
    public function sitemapLogs(): array
    {
        $rows = DB::table('player_matches as pm')
            ->leftJoin('log_dates as ld', 'ld.log_id', '=', 'pm.match_id')
            ->whereNotIn('pm.match_id', DB::table('log_blacklist')->select('log_id'))
            ->groupBy('pm.match_id')
            ->orderByDesc('pm.match_id')
            ->select('pm.match_id as id', DB::raw('MIN(ld.date) as date'))
            ->get();

        $logs = [];
        foreach ($rows as $row) {
            $logs[] = [
                'id' => (int) $row->id,
                'date' => $row->date !== null ? (int) $row->date : null,
            ];
        }

        return $logs;
    }

    /**
     * Invalide le cache JSON des Match Stats.
     */
    public function invalidateLogsCache(): void
    {
        $cacheFile = hlfr_data_path('cache_hlfr_logs.json');
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}
