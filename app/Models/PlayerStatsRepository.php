<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

/**
 * Agrégations de statistiques de jeu par joueur (table player_matches).
 */
final class PlayerStatsRepository
{
    public function totalMatches(string $steamid3, string $mode): int
    {
        return (int) DB::table('player_stats')
            ->where('steamid', $steamid3)
            ->where('game_mode', $mode)
            ->value('count');
    }

    /**
     * @return array<int, object>
     */
    public function topMaps(string $steamid3, string $mode): array
    {
        return DB::table('player_matches')
            ->select('map_name', DB::raw('COUNT(map_name) AS total'))
            ->where('steamid', $steamid3)
            ->where('game_mode', $mode)
            ->where('map_name', 'not like', '% + %')
            ->groupBy('map_name')
            ->orderByDesc('total')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<int, object>
     */
    public function classesPlayed(string $steamid3, string $mode): array
    {
        return DB::table('player_matches')
            ->select('class_played', DB::raw('COUNT(class_played) AS total'))
            ->where('steamid', $steamid3)
            ->where('game_mode', $mode)
            ->groupBy('class_played')
            ->orderByDesc('total')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Statistiques globales d'un joueur pour un mode (DPM, kills, morts, K/D, classes tuées…).
     */
    public function aggregate(string $steamid3, string $mode): array
    {
        try {
            $t = DB::table('player_matches')
                ->selectRaw('COALESCE(AVG(CASE WHEN length > 0 THEN dapm END), 0) AS average_dpm')
                ->selectRaw('COALESCE(AVG(CASE WHEN length > 0 THEN dmg_taken * 60.0 / length END), 0) AS average_dtpm')
                ->selectRaw('COALESCE(SUM(dmg_taken), 0) AS total_dmg_taken')
                ->selectRaw('COALESCE(SUM(airshots), 0) AS total_airshots')
                ->selectRaw('COALESCE(SUM(captures), 0) AS total_captures')
                ->selectRaw('COALESCE(SUM(medkits_hp), 0) AS total_medkits_hp')
                ->selectRaw('COALESCE(SUM(kills), 0) AS total_kills')
                ->selectRaw('COALESCE(SUM(deaths), 0) AS total_deaths')
                ->selectRaw('COALESCE(SUM(assists), 0) AS total_assists')
                ->where('steamid', $steamid3)
                ->where('game_mode', $mode)
                ->first();

            if ($t === null) {
                return $this->emptyAggregate();
            }

            $kd = 0;
            if ((int) $t->total_deaths > 0) {
                $kd = round((int) $t->total_kills / (int) $t->total_deaths, 2);
            } elseif ((int) $t->total_kills > 0) {
                $kd = (int) $t->total_kills;
            }

            // Fusion des JSON "classes_killed" de chaque match
            $rows = DB::table('player_matches')
                ->where('steamid', $steamid3)
                ->where('game_mode', $mode)
                ->whereNotNull('classes_killed')
                ->where('classes_killed', '!=', '')
                ->pluck('classes_killed');

            $classesKilled = [];
            foreach ($rows as $json) {
                $decoded = json_decode((string) $json, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $class => $count) {
                        $classesKilled[$class] = ($classesKilled[$class] ?? 0) + (int) $count;
                    }
                }
            }
            arsort($classesKilled);

            return [
                'average_dpm'      => round((float) $t->average_dpm, 1),
                'average_dtpm'     => round((float) $t->average_dtpm, 1),
                'total_dmg_taken'  => (int) $t->total_dmg_taken,
                'total_airshots'   => (int) $t->total_airshots,
                'total_captures'   => (int) $t->total_captures,
                'total_medkits_hp' => (int) $t->total_medkits_hp,
                'total_kills'      => (int) $t->total_kills,
                'total_deaths'     => (int) $t->total_deaths,
                'total_assists'    => (int) $t->total_assists,
                'kd_ratio'         => $kd,
                'classes_killed'   => $classesKilled,
            ];
        } catch (\Throwable) {
            return $this->emptyAggregate();
        }
    }

    /**
     * Activité : nombre de matchs par jour sur les 3 derniers mois (tous modes confondus).
     *
     * @return array<string, int>
     */
    public function activity(string $steamid3): array
    {
        $rows = DB::table('player_matches as pm')
            ->join('log_dates as ld', 'ld.log_id', '=', 'pm.match_id')
            ->where('pm.steamid', $steamid3)
            ->whereNotNull('ld.date')
            ->where('ld.date', '>=', time() - 90 * 86400)
            ->groupBy(DB::raw('DATE(FROM_UNIXTIME(ld.date))'))
            ->selectRaw("DATE(FROM_UNIXTIME(ld.date)) AS day, COUNT(DISTINCT pm.match_id) AS matches")
            ->get();

        $activity = [];
        foreach ($rows as $row) {
            $activity[$row->day] = (int) $row->matches;
        }

        return $activity;
    }

    /**
     * Derniers matchs d'un joueur pour un mode (avec date issue de log_dates).
     */
    public function recentMatches(string $steamid3, string $mode, int $limit = 5): array
    {
        $rows = DB::table('player_matches as pm')
            ->leftJoin('log_dates as ld', 'ld.log_id', '=', 'pm.match_id')
            ->where('pm.steamid', $steamid3)
            ->where('pm.game_mode', $mode)
            ->orderByDesc('pm.match_id')
            ->limit($limit)
            ->select(
                'pm.match_id', 'pm.map_name', 'pm.class_played',
                'pm.dmg', 'pm.kills', 'pm.deaths', 'pm.assists', 'pm.won',
                'ld.date AS match_date',
            )
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'match_id' => $r->match_id,
                'map_name' => $r->map_name,
                'class_played' => $r->class_played,
                'dmg' => (int) ($r->dmg ?? 0),
                'kills' => (int) ($r->kills ?? 0),
                'deaths' => (int) ($r->deaths ?? 0),
                'assists' => (int) ($r->assists ?? 0),
                'won' => is_null($r->won) ? null : (int) $r->won,
                'match_date' => !empty($r->match_date) ? date('d/m/Y', (int) $r->match_date) : null,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAggregate(): array
    {
        return [
            'average_dpm'      => 0,
            'average_dtpm'     => 0,
            'total_dmg_taken'  => 0,
            'total_airshots'   => 0,
            'total_captures'   => 0,
            'total_medkits_hp' => 0,
            'total_kills'      => 0,
            'total_deaths'     => 0,
            'total_assists'    => 0,
            'kd_ratio'         => 0,
            'classes_killed'   => [],
        ];
    }
}
