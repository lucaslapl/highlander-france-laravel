<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Couche d'accès au nouveau moteur de stats multi-équipes :
 * saisons officielles, historique joueur, rattachement des logs aux matchs
 * officiels et visibilité roster (stat_lineup).
 */
final class OfficialMatchRepository
{
    // ─── Saisons officielles ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $season
     */
    public function upsertSeason(array $season): void
    {
        DB::table('official_seasons')->upsert(
            [
                'competition_id' => (int) $season['competition_id'],
                'name' => (string) ($season['name'] ?? ''),
                'category' => (string) ($season['category'] ?? ''),
                'type' => $season['type'] ?? null,
                'game_mode' => (string) ($season['game_mode'] ?? ''),
                'season_label' => $season['season_label'] ?? null,
                'fetched_at' => time(),
            ],
            ['competition_id'],
            ['name', 'category', 'type', 'game_mode', 'season_label', 'fetched_at'],
        );
    }

    public function season(int $competitionId): ?array
    {
        $row = DB::table('official_seasons')->where('competition_id', $competitionId)->first();

        return $row !== null ? (array) $row : null;
    }

    // ─── Historique joueur (player_season_matches) ─────────────────────────

    /**
     * Ajoute les matchs officiels d'un joueur (insertion ignorée si déjà présents).
     *
     * @param  array<int, array<string, mixed>>  $matches
     */
    public function insertPlayerMatches(string $steamid, array $matches): void
    {
        if ($matches === []) {
            return;
        }

        $rows = [];
        foreach ($matches as $m) {
            $rows[] = [
                'steamid' => $steamid,
                'match_id' => (int) ($m['match_id'] ?? 0),
                'competition_id' => (int) ($m['competition_id'] ?? 0),
                'team_id' => (int) ($m['team_id'] ?? 0),
                'opponent_team_id' => ($m['opponent_team_id'] ?? null) !== null ? (int) $m['opponent_team_id'] : null,
                'game_mode' => (string) ($m['game_mode'] ?? ''),
                'time' => ($m['time'] ?? null) !== null ? (int) $m['time'] : null,
                'round' => ($m['round'] ?? null) !== null ? (string) $m['round'] : null,
                'r1' => ($m['r1'] ?? null) !== null ? (int) $m['r1'] : null,
                'r2' => ($m['r2'] ?? null) !== null ? (int) $m['r2'] : null,
                'team_is_clan1' => (bool) ($m['team_is_clan1'] ?? true),
            ];
        }

        DB::table('player_season_matches')->insertOrIgnore($rows);
    }

    public function deletePlayerHistory(string $steamid): void
    {
        DB::table('player_season_matches')->where('steamid', $steamid)->delete();
    }

    public function hasPlayerHistory(string $steamid): bool
    {
        return DB::table('player_season_matches')->where('steamid', $steamid)->exists();
    }

    /**
     * Matchs officiels d'un joueur au sein d'une équipe (optionnellement filtrés
     * par mode), triés du plus récent au plus ancien.
     *
     * @return array<int, array<string, mixed>>
     */
    public function playerTeamMatches(string $steamid, int $teamId, ?string $gameMode): array
    {
        return DB::table('player_season_matches as psm')
            ->join('official_seasons as s', 's.competition_id', '=', 'psm.competition_id')
            ->where('psm.steamid', $steamid)
            ->where('psm.team_id', $teamId)
            ->when($gameMode !== null, fn ($q) => $q->where('psm.game_mode', $gameMode))
            ->orderByDesc('psm.time')
            ->select(
                'psm.match_id', 'psm.competition_id', 'psm.team_id', 'psm.opponent_team_id',
                'psm.game_mode', 'psm.time', 'psm.round', 'psm.r1', 'psm.r2', 'psm.team_is_clan1',
                's.name as competition_name', 's.category as competition_category',
            )
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Compétitions de saison d'un joueur pour une équipe, triées par date de
     * match la plus récente (desc), avec le timestamp max par compétition.
     *
     * @return array<int, array{competition_id: int, name: string, season_label: string|null, max_time: int}>
     */
    public function playerTeamSeasons(string $steamid, int $teamId, ?string $gameMode): array
    {
        return DB::table('player_season_matches as psm')
            ->join('official_seasons as s', 's.competition_id', '=', 'psm.competition_id')
            ->where('psm.steamid', $steamid)
            ->where('psm.team_id', $teamId)
            ->when($gameMode !== null, fn ($q) => $q->where('psm.game_mode', $gameMode))
            ->groupBy('psm.competition_id', 's.name', 's.season_label')
            ->orderByDesc(DB::raw('MAX(psm.time)'))
            ->select(
                'psm.competition_id',
                's.name',
                's.season_label',
                DB::raw('MAX(psm.time) AS max_time'),
            )
            ->get()
            ->map(static fn ($row): array => [
                'competition_id' => (int) $row->competition_id,
                'name' => (string) $row->name,
                'season_label' => $row->season_label !== null ? (string) $row->season_label : null,
                'max_time' => (int) ($row->max_time ?? 0),
            ])
            ->all();
    }

    // ─── Rattachement des logs (official_match_logs) ───────────────────────

    /**
     * @param array<string, mixed> $data
     */
    public function attachLog(array $data): void
    {
        DB::table('official_match_logs')->upsert(
            [
                'log_id' => (int) $data['log_id'],
                'match_id' => (int) $data['match_id'],
                'category' => (string) $data['category'],
                'red_team_id' => ($data['red_team_id'] ?? null) !== null ? (int) $data['red_team_id'] : null,
                'blue_team_id' => ($data['blue_team_id'] ?? null) !== null ? (int) $data['blue_team_id'] : null,
                'source' => (string) ($data['source'] ?? 'auto'),
                'added_by' => $data['added_by'] ?? null,
                'created_at' => now(),
            ],
            ['log_id'],
            ['match_id', 'category', 'red_team_id', 'blue_team_id', 'source', 'added_by', 'created_at'],
        );
    }

    public function detachLog(int $logId): bool
    {
        return DB::table('official_match_logs')->where('log_id', $logId)->delete() > 0;
    }

    public function existsLog(int $logId): bool
    {
        return DB::table('official_match_logs')->where('log_id', $logId)->exists();
    }

    public function categoryFor(int $logId): ?string
    {
        $category = DB::table('official_match_logs')->where('log_id', $logId)->value('category');

        return $category !== null ? (string) $category : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function infoFor(int $logId): ?array
    {
        $row = DB::table('official_match_logs')
            ->leftJoin('player_season_matches as psm', 'psm.match_id', '=', 'official_match_logs.match_id')
            ->where('official_match_logs.log_id', $logId)
            ->select(
                'official_match_logs.log_id',
                'official_match_logs.match_id',
                'official_match_logs.category',
                'official_match_logs.red_team_id',
                'official_match_logs.blue_team_id',
                'official_match_logs.source',
                'official_match_logs.added_by',
                DB::raw('MAX(psm.time) AS match_time'),
            )
            ->groupBy(
                'official_match_logs.log_id',
                'official_match_logs.match_id',
                'official_match_logs.category',
                'official_match_logs.red_team_id',
                'official_match_logs.blue_team_id',
                'official_match_logs.source',
                'official_match_logs.added_by',
            )
            ->first();

        if ($row === null) {
            return null;
        }
        $info = [
            'log_id' => (int) $row->log_id,
            'match_id' => (int) $row->match_id,
            'category' => (string) $row->category,
            'red_team_id' => $row->red_team_id !== null ? (int) $row->red_team_id : null,
            'blue_team_id' => $row->blue_team_id !== null ? (int) $row->blue_team_id : null,
            'source' => (string) $row->source,
            'added_by' => $row->added_by !== null ? (string) $row->added_by : null,
            'match_time' => $row->match_time !== null ? (int) $row->match_time : null,
        ];

        return $info;
    }

    /**
     * Logs officiels non encore traités par le pipeline stats
     * (clé : log_id => catégorie), hors blacklist logs et équipes.
     *
     * @return array<int, string>
     */
    public function pendingLogIds(): array
    {
        $query = DB::table('official_match_logs')
            ->whereNotIn('log_id', DB::table('processed_logs')->select('id'))
            ->whereNotIn('log_id', DB::table('log_blacklist')->select('log_id'));

        $teams = DB::table('team_blacklist')->pluck('team_id')->map('intval')->all();
        if ($teams !== []) {
            $query->whereNotIn('log_id', DB::table('official_match_logs')
                ->where(function ($q) use ($teams): void {
                    $q->whereIn('red_team_id', $teams)
                        ->orWhereIn('blue_team_id', $teams);
                })
                ->select('log_id'));
        }

        return $query
            ->orderByDesc('created_at')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(int) $row->log_id => (string) $row->category])
            ->all();
    }

    /**
     * Logs officiels rattachés à un match.
     *
     * @return array<int, array<string, mixed>>
     */
    public function logsForMatch(int $matchId): array
    {
        $blacklisted = (new MatchLogRepository)->blacklistedIds();

        return DB::table('official_match_logs as oml')
            ->leftJoin('match_scores as ms', 'ms.match_id', '=', 'oml.log_id')
            ->where('oml.match_id', $matchId)
            ->orderByDesc('oml.created_at')
            ->select(
                'oml.log_id', 'oml.category', 'oml.red_team_id', 'oml.blue_team_id',
                'oml.source', 'oml.added_by', 'oml.created_at',
                'ms.red_score', 'ms.blue_score',
            )
            ->get()
            ->map(function ($row) use ($blacklisted): array {
                return [
                    'log_id' => (int) $row->log_id,
                    'category' => (string) $row->category,
                    'red_team_id' => $row->red_team_id !== null ? (int) $row->red_team_id : null,
                    'blue_team_id' => $row->blue_team_id !== null ? (int) $row->blue_team_id : null,
                    'source' => (string) $row->source,
                    'added_by' => $row->added_by !== null ? (string) $row->added_by : null,
                    'created_at' => $row->created_at,
                    'red_score' => $row->red_score !== null ? (int) $row->red_score : null,
                    'blue_score' => $row->blue_score !== null ? (int) $row->blue_score : null,
                    'blacklisted' => in_array((int) $row->log_id, $blacklisted, true),
                ];
            })
            ->all();
    }

    /**
     * Logs officiels des matchs d'une fenêtre (pour l'agrégation des stats).
     *
     * @param  int[]  $matchIds
     * @return array<int, array<string, mixed>>
     */
    public function logsForMatches(array $matchIds): array
    {
        if ($matchIds === []) {
            return [];
        }

        $blacklisted = (new MatchLogRepository)->blacklistedIds();

        return DB::table('official_match_logs as oml')
            ->leftJoin('match_scores as ms', 'ms.match_id', '=', 'oml.log_id')
            ->whereIn('oml.match_id', $matchIds)
            ->select(
                'oml.log_id', 'oml.match_id', 'oml.category', 'oml.red_team_id', 'oml.blue_team_id',
                'ms.red_score', 'ms.blue_score',
            )
            ->get()
            ->map(static function ($row) use ($blacklisted): array {
                return [
                    'log_id' => (int) $row->log_id,
                    'match_id' => (int) $row->match_id,
                    'category' => (string) $row->category,
                    'red_team_id' => $row->red_team_id !== null ? (int) $row->red_team_id : null,
                    'blue_team_id' => $row->blue_team_id !== null ? (int) $row->blue_team_id : null,
                    'red_score' => $row->red_score !== null ? (int) $row->red_score : null,
                    'blue_score' => $row->blue_score !== null ? (int) $row->blue_score : null,
                    'blacklisted' => in_array((int) $row->log_id, $blacklisted, true),
                ];
            })
            ->all();
    }

    // ─── Blacklist équipes ──────────────────────────────────────────────────

    /**
     * @return int[]
     */
    public function blacklistedTeamIds(): array
    {
        return array_map('intval', DB::table('team_blacklist')->pluck('team_id')->all());
    }

    public function blacklistTeam(int $teamId, string $reason, string $addedBy): void
    {
        DB::table('team_blacklist')->insertOrIgnore([
            'team_id' => $teamId,
            'reason' => $reason,
            'added_by' => $addedBy,
            'created_at' => now(),
        ]);
    }

    public function unblacklistTeam(int $teamId): void
    {
        DB::table('team_blacklist')->where('team_id', $teamId)->delete();
    }

    // ─── Visibilité lineup (stat_lineup) ────────────────────────────────────

    /**
     * @return array<string, bool> steamid => visible
     */
    public function lineup(int $teamId): array
    {
        return DB::table('stat_lineup')
            ->where('team_id', $teamId)
            ->get()
            ->mapWithKeys(static fn ($row): array => [(string) $row->steamid => (bool) $row->visible])
            ->all();
    }

    public function setLineup(string $steamid, int $teamId, bool $visible): void
    {
        DB::table('stat_lineup')->upsert(
            ['team_id' => $teamId, 'steamid' => $steamid, 'visible' => $visible, 'updated_at' => now()],
            ['team_id', 'steamid'],
            ['visible', 'updated_at'],
        );
    }
}
