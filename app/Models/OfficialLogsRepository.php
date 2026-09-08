<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\MatchFormat;
use Illuminate\Support\Facades\DB;

/**
 * Logs officiels de ligue (table official_logs) et agrégations de stats
 * calculées exclusivement sur ces logs (sources des overlays OBS).
 */
final class OfficialLogsRepository
{
    public function attach(
        int $logId,
        string $category,
        ?int $etf2lMatchId = null,
        ?int $scopeTeamId = null,
        ?int $redTeamId = null,
        ?int $blueTeamId = null,
        string $source = 'manual',
        ?string $addedBy = null,
    ): void {
        DB::table('official_logs')->upsert(
            [
                'log_id' => $logId,
                'category' => $category,
                'etf2l_match_id' => $etf2lMatchId,
                'scope_team_id' => $scopeTeamId,
                'red_team_id' => $redTeamId,
                'blue_team_id' => $blueTeamId,
                'source' => $source,
                'added_by' => $addedBy,
                'created_at' => now(),
            ],
            ['log_id'],
            [
                'category', 'etf2l_match_id', 'scope_team_id',
                'red_team_id', 'blue_team_id', 'source', 'added_by', 'created_at',
            ],
        );
    }

    public function detach(int $logId): bool
    {
        return DB::table('official_logs')->where('log_id', $logId)->delete() > 0;
    }

    public function exists(int $logId): bool
    {
        return DB::table('official_logs')->where('log_id', $logId)->exists();
    }

    /**
     * Catégorie ('9v9' | '6s') d'un log officiel, ou null s'il n'est pas attaché.
     */
    public function categoryFor(int $logId): ?string
    {
        $category = DB::table('official_logs')->where('log_id', $logId)->value('category');

        return $category !== null ? (string) $category : null;
    }

    /**
     * Ligne complète d'un log officiel (ou null).
     *
     * @return array<string, mixed>|null
     */
    public function infoFor(int $logId): ?array
    {
        $row = DB::table('official_logs')->where('log_id', $logId)->first();

        return $row !== null ? (array) $row : null;
    }

    /**
     * Logs officiels non encore traités par le pipeline stats
     * (clé : log_id => catégorie). Les logs blacklistés sont ignorés.
     *
     * @return array<int, string>
     */
    public function pendingLogIds(): array
    {
        return DB::table('official_logs')
            ->whereNotIn('log_id', DB::table('processed_logs')->select('id'))
            ->whereNotIn('log_id', DB::table('log_blacklist')->select('log_id'))
            ->orderByDesc('created_at')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(int) $row->log_id => (string) $row->category])
            ->all();
    }

    /**
     * Logs officiels rattachés à un match ETF2L.
     *
     * @return array<int, array<string, mixed>>
     */
    public function logsForMatch(int $matchId): array
    {
        $rows = DB::table('official_logs as ol')
            ->leftJoin('log_dates as ld', 'ld.log_id', '=', 'ol.log_id')
            ->leftJoin('match_scores as ms', 'ms.match_id', '=', 'ol.log_id')
            ->leftJoin('log_blacklist as lb', 'lb.log_id', '=', 'ol.log_id')
            ->where('ol.etf2l_match_id', $matchId)
            ->orderByDesc('ol.created_at')
            ->select(
                'ol.log_id', 'ol.red_team_id', 'ol.blue_team_id', 'ol.source', 'ol.created_at',
                'ld.date', 'ms.red_score', 'ms.blue_score',
                DB::raw('lb.log_id IS NOT NULL AS blacklisted'),
            )
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        $blacklisted = (new MatchLogRepository)->blacklistedIds();

        foreach ($rows as &$row) {
            $row['log_id'] = (int) $row['log_id'];
            $row['red_team_id'] = $row['red_team_id'] !== null ? (int) $row['red_team_id'] : null;
            $row['blue_team_id'] = $row['blue_team_id'] !== null ? (int) $row['blue_team_id'] : null;
            $row['red_score'] = $row['red_score'] !== null ? (int) $row['red_score'] : null;
            $row['blue_score'] = $row['blue_score'] !== null ? (int) $row['blue_score'] : null;
            $row['date'] = $row['date'] !== null ? (int) $row['date'] : null;
            $row['blacklisted'] = in_array($row['log_id'], $blacklisted, true);
        }
        unset($row);

        return $rows;
    }

    /**
     * Logs officiels auxquels une équipe a participé dans une catégorie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function logsForTeam(int $teamId, string $category): array
    {
        return DB::table('official_logs as ol')
            ->leftJoin('log_dates as ld', 'ld.log_id', '=', 'ol.log_id')
            ->leftJoin('match_scores as ms', 'ms.match_id', '=', 'ol.log_id')
            ->leftJoin('etf2l_matches as em', 'em.match_id', '=', 'ol.etf2l_match_id')
            ->where('ol.category', $category)
            ->whereNotIn('ol.log_id', DB::table('log_blacklist')->select('log_id'))
            ->where(function ($q) use ($teamId): void {
                $q->where('ol.red_team_id', $teamId)
                    ->orWhere('ol.blue_team_id', $teamId)
                    ->orWhere('ol.scope_team_id', $teamId);
            })
            ->orderByDesc('ld.date')
            ->select(
                'ol.log_id', 'ol.etf2l_match_id', 'ol.red_team_id', 'ol.blue_team_id', 'ol.scope_team_id',
                'ol.source', 'ld.date', 'ms.red_score', 'ms.blue_score',
                'em.team1_name', 'em.team2_name',
            )
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    // ─── Agrégations factorisées (sources des overlays) ──────────────────────

    private function blacklistedIds(): array
    {
        return (new MatchLogRepository)->blacklistedIds();
    }

    /**
     * Stats officielles globales d'un joueur pour une catégorie.
     *
     * @return array<string, mixed>
     */
    public function officialPlayerStats(string $steamid3, string $category): array
    {
        $blacklist = $this->blacklistedIds();

        $row = DB::table('player_matches as pm')
            ->join('official_logs as ol', 'ol.log_id', '=', 'pm.match_id')
            ->where('pm.steamid', $steamid3)
            ->where('ol.category', $category)
            ->when($blacklist !== [], fn ($q) => $q->whereNotIn('pm.match_id', $blacklist))
            ->selectRaw('COALESCE(SUM(pm.length), 0) AS seconds')
            ->selectRaw('COUNT(*) AS matches')
            ->selectRaw('COALESCE(SUM(pm.kills), 0) AS kills')
            ->selectRaw('COALESCE(SUM(pm.deaths), 0) AS deaths')
            ->selectRaw('COALESCE(SUM(pm.dmg), 0) AS dmg')
            ->selectRaw('COALESCE(SUM(pm.assists), 0) AS assists')
            ->selectRaw('COALESCE(SUM(pm.heal), 0) AS heal')
            ->selectRaw('COALESCE(AVG(CASE WHEN pm.length > 0 THEN pm.dapm END), 0) AS avg_dpm')
            ->selectRaw('COALESCE(SUM(pm.airshots), 0) AS airshots')
            ->selectRaw('COALESCE(SUM(pm.captures), 0) AS captures')
            ->selectRaw('COALESCE(SUM(CASE WHEN pm.won = 1 THEN 1 ELSE 0 END), 0) AS wins')
            ->selectRaw('COALESCE(SUM(CASE WHEN pm.won IS NOT NULL THEN 1 ELSE 0 END), 0) AS decided')
            ->first();

        return $this->buildPlayerAggregate($row);
    }

    /**
     * Stats officielles d'un joueur au sein d'une équipe précise (les logs
     * officiels où cette équipe a joué, et uniquement les matchs où le joueur
     * était du côté de l'équipe).
     *
     * @return array<string, mixed>
     */
    public function officialPlayerStatsForTeam(string $steamid3, int $teamId, string $category): array
    {
        $blacklist = $this->blacklistedIds();

        $row = DB::table('player_matches as pm')
            ->join('official_logs as ol', 'ol.log_id', '=', 'pm.match_id')
            ->where('pm.steamid', $steamid3)
            ->where('ol.category', $category)
            ->when($blacklist !== [], fn ($q) => $q->whereNotIn('pm.match_id', $blacklist))
            ->where(function ($q) use ($teamId): void {
                $q->where(function ($r) use ($teamId): void {
                    $r->where('ol.red_team_id', $teamId)->where('pm.team', 'red');
                })->orWhere(function ($b) use ($teamId): void {
                    $b->where('ol.blue_team_id', $teamId)->where('pm.team', 'blue');
                })->orWhere('ol.scope_team_id', $teamId);
            })
            ->selectRaw('COALESCE(SUM(pm.length), 0) AS seconds')
            ->selectRaw('COUNT(*) AS matches')
            ->selectRaw('COALESCE(SUM(pm.kills), 0) AS kills')
            ->selectRaw('COALESCE(SUM(pm.deaths), 0) AS deaths')
            ->selectRaw('COALESCE(SUM(pm.dmg), 0) AS dmg')
            ->selectRaw('COALESCE(SUM(pm.assists), 0) AS assists')
            ->selectRaw('COALESCE(SUM(pm.heal), 0) AS heal')
            ->selectRaw('COALESCE(AVG(CASE WHEN pm.length > 0 THEN pm.dapm END), 0) AS avg_dpm')
            ->selectRaw('COALESCE(SUM(pm.airshots), 0) AS airshots')
            ->selectRaw('COALESCE(SUM(pm.captures), 0) AS captures')
            ->selectRaw('COALESCE(SUM(CASE WHEN pm.won = 1 THEN 1 ELSE 0 END), 0) AS wins')
            ->selectRaw('COALESCE(SUM(CASE WHEN pm.won IS NOT NULL THEN 1 ELSE 0 END), 0) AS decided')
            ->first();

        return $this->buildPlayerAggregate($row);
    }

    /**
     * Stats officielles agrégées d'une équipe (tous les joueurs de ses matchs).
     *
     * @return array<string, mixed>
     */
    public function officialTeamStats(int $teamId, string $category): array
    {
        $blacklist = $this->blacklistedIds();

        $players = DB::table('player_matches as pm')
            ->join('official_logs as ol', 'ol.log_id', '=', 'pm.match_id')
            ->where('ol.category', $category)
            ->when($blacklist !== [], fn ($q) => $q->whereNotIn('pm.match_id', $blacklist))
            ->where(function ($q) use ($teamId): void {
                $q->where(function ($r) use ($teamId): void {
                    $r->where('ol.red_team_id', $teamId)->where('pm.team', 'red');
                })->orWhere(function ($b) use ($teamId): void {
                    $b->where('ol.blue_team_id', $teamId)->where('pm.team', 'blue');
                })->orWhere('ol.scope_team_id', $teamId);
            })
            ->select(
                'pm.steamid', 'pm.match_id', 'pm.kills', 'pm.deaths', 'pm.dmg', 'pm.assists',
                'pm.heal', 'pm.airshots', 'pm.captures', 'pm.length', 'pm.dapm', 'pm.won',
            )
            ->get();

        $total = [
            'matches' => 0, 'kills' => 0, 'deaths' => 0, 'dmg' => 0, 'assists' => 0,
            'heal' => 0, 'airshots' => 0, 'captures' => 0, 'seconds' => 0,
            'wins' => 0, 'decided' => 0, 'dpmSum' => 0, 'dpmCount' => 0,
        ];

        $byPlayer = [];
        $matchIds = [];

        foreach ($players as $row) {
            $sid = (string) $row->steamid;
            $matchIds[(int) ($row->match_id ?? 0)] = true;
            if (! isset($byPlayer[$sid])) {
                $byPlayer[$sid] = [
                    'steamid' => $sid, 'matches' => 0, 'kills' => 0, 'deaths' => 0,
                    'dmg' => 0, 'assists' => 0, 'heal' => 0, 'airshots' => 0,
                    'captures' => 0, 'seconds' => 0, 'wins' => 0, 'decided' => 0,
                    'dpmSum' => 0, 'dpmCount' => 0,
                ];
            }

            $p = &$byPlayer[$sid];
            $p['matches']++;
            $p['kills'] += (int) $row->kills;
            $p['deaths'] += (int) $row->deaths;
            $p['dmg'] += (int) $row->dmg;
            $p['assists'] += (int) $row->assists;
            $p['heal'] += (int) $row->heal;
            $p['airshots'] += (int) $row->airshots;
            $p['captures'] += (int) $row->captures;
            $p['seconds'] += (int) $row->length;
            if ($row->won !== null) {
                $p['decided']++;
                if ((int) $row->won === 1) {
                    $p['wins']++;
                }
            }
            if ((int) $row->length > 0) {
                $p['dpmSum'] += (int) $row->dapm;
                $p['dpmCount']++;
            }
            unset($p);

            $total['kills'] += (int) $row->kills;
            $total['deaths'] += (int) $row->deaths;
            $total['dmg'] += (int) $row->dmg;
            $total['assists'] += (int) $row->assists;
            $total['heal'] += (int) $row->heal;
            $total['airshots'] += (int) $row->airshots;
            $total['captures'] += (int) $row->captures;
            $total['seconds'] += (int) $row->length;
            if ($row->won !== null) {
                $total['decided']++;
                if ((int) $row->won === 1) {
                    $total['wins']++;
                }
            }
            if ((int) $row->length > 0) {
                $total['dpmSum'] += (int) $row->dapm;
                $total['dpmCount']++;
            }
        }

        $total['matches'] = count($matchIds);

        $playerCards = [];
        foreach ($byPlayer as $sid => $p) {
            $playerCards[$sid] = $this->finalizePlayer($p);
        }

        return array_merge($this->finalizePlayer($total, true), ['players' => $playerCards]);
    }

    /**
     * Stats officielles agrégées par match (recent matches d'une équipe).
     *
     * @return array<int, array<string, mixed>>
     */
    public function teamRecentMatches(int $teamId, string $category, int $limit = 12): array
    {
        $rows = $this->logsForTeam($teamId, $category);
        $list = [];

        $byLog = [];
        foreach ($rows as $row) {
            $lid = (int) $row['log_id'];
            if (! isset($byLog[$lid])) {
                $byLog[$lid] = [
                    'log_id' => $lid,
                    'date' => $row['date'] !== null ? (int) $row['date'] : null,
                    'red_score' => $row['red_score'] !== null ? (int) $row['red_score'] : null,
                    'blue_score' => $row['blue_score'] !== null ? (int) $row['blue_score'] : null,
                    'red_team_id' => $row['red_team_id'] !== null ? (int) $row['red_team_id'] : null,
                    'blue_team_id' => $row['blue_team_id'] !== null ? (int) $row['blue_team_id'] : null,
                    'etf2l_match_id' => $row['etf2l_match_id'] !== null ? (int) $row['etf2l_match_id'] : null,
                ];
            }
        }

        foreach ($byLog as $log) {
            $isRed = $log['red_team_id'] === $teamId;
            $isBlue = $log['blue_team_id'] === $teamId;
            $score = $isRed ? $log['red_score'] : ($isBlue ? $log['blue_score'] : null);
            $other = $isRed ? $log['blue_score'] : ($isBlue ? $log['red_score'] : null);

            $list[] = [
                'log_id' => $log['log_id'],
                'date' => $log['date'],
                'score' => $score,
                'other_score' => $other,
                'result' => MatchFormat::teamResult($score, $other),
                'etf2l_match_id' => $log['etf2l_match_id'],
            ];
        }

        usort($list, static fn (array $a, array $b): int => ($b['date'] ?? 0) <=> ($a['date'] ?? 0));

        return array_slice($list, 0, $limit);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  object|null  $row  Ligne du query builder (ou null).
     * @return array<string, mixed>
     */
    private function buildPlayerAggregate(?object $row): array
    {
        if ($row === null) {
            $row = (object) ['seconds' => 0, 'matches' => 0, 'kills' => 0, 'deaths' => 0, 'dmg' => 0,
                'assists' => 0, 'heal' => 0, 'avg_dpm' => 0, 'airshots' => 0, 'captures' => 0, 'wins' => 0, 'decided' => 0];
        }

        return $this->finalizePlayer([
            'seconds' => (int) $row->seconds,
            'matches' => (int) $row->matches,
            'kills' => (int) $row->kills,
            'deaths' => (int) $row->deaths,
            'dmg' => (int) $row->dmg,
            'assists' => (int) $row->assists,
            'heal' => (int) $row->heal,
            'airshots' => (int) $row->airshots,
            'captures' => (int) $row->captures,
            'wins' => (int) $row->wins,
            'decided' => (int) $row->decided,
            'dpmSum' => round((float) $row->avg_dpm, 2) * max(1, (int) $row->matches),
            'dpmCount' => max(1, (int) $row->matches),
        ]);
    }

    /**
     * Formate une ligne de compteurs en statistiques lisibles (K/D, DPM, winrate…).
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function finalizePlayer(array $p, bool $isTeam = false): array
    {
        $kills = (int) ($p['kills'] ?? 0);
        $deaths = (int) ($p['deaths'] ?? 0);
        $dpmCount = max(1, (int) ($p['dpmCount'] ?? 0));
        $decided = (int) ($p['decided'] ?? 0);

        if ($isTeam && empty($p['matches'])) {
            $p['matches'] = (int) ($p['match_count'] ?? 0);
        }

        $kd = $deaths > 0 ? round($kills / $deaths, 2) : ($kills > 0 ? (float) $kills : 0.0);

        return [
            'matches' => (int) ($p['matches'] ?? 0),
            'kills' => $kills,
            'deaths' => $deaths,
            'dmg' => (int) ($p['dmg'] ?? 0),
            'assists' => (int) ($p['assists'] ?? 0),
            'heal' => (int) ($p['heal'] ?? 0),
            'airshots' => (int) ($p['airshots'] ?? 0),
            'captures' => (int) ($p['captures'] ?? 0),
            'seconds' => (int) ($p['seconds'] ?? 0),
            'kd' => $kd,
            'dpm' => round((float) ($p['dpmSum'] ?? 0) / $dpmCount, 0),
            'winrate' => $decided > 0 ? round((int) ($p['wins'] ?? 0) * 100 / $decided, 0) : null,
            'wins' => (int) ($p['wins'] ?? 0),
            'losses' => $decided - (int) ($p['wins'] ?? 0),
        ];
    }
}
