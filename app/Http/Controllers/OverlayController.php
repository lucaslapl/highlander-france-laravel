<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OfficialLogsRepository;
use App\Services\SteamId;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Overlays OBS statiques (fond sombre, aucun JS) calculés sur les logs officiels
 * uniquement (table official_logs). Conçu pour être capturé en source navigateur OBS.
 */
final class OverlayController extends Controller
{
    /**
     * Stats officielles d'une équipe par catégorie + roster (ETF2L ou France).
     * GET /overlay/equipe/{teamId}
     */
    public function equipe(int $teamId): View
    {
        $team = $this->teamInfo($teamId);
        $requested = strtolower((string) (request()->query('category') ?? $team['category'] ?? '9v9'));
        $category = in_array($requested, ['9v9', '6s'], true) ? $requested : '9v9';

        $repo = new OfficialLogsRepository;
        $teamStats = $repo->officialTeamStats($teamId, $category);

        $roster = $this->teamRoster($teamId);
        $rows = [];
        foreach ($roster as $player) {
            $stats = $repo->officialPlayerStatsForTeam($player['steamid'], $teamId, $category);
            $rows[] = array_merge($player, $stats);
        }

        usort($rows, static fn (array $a, array $b): int => ($b['matches'] ?? 0) <=> ($a['matches'] ?? 0));

        return view('overlays.equipe', [
            'background' => $this->background(),
            'team' => $team,
            'category' => $category,
            'stats' => $teamStats,
            'rows' => $rows,
        ]);
    }

    /**
     * Logs officiels d'un match ETF2L (scores + perfs du jour par camp).
     * GET /overlay/match/{matchId}
     */
    public function match(int $matchId): View
    {
        $match = DB::table('etf2l_matches')->where('match_id', $matchId)->first();
        $repo = new OfficialLogsRepository;

        $logs = [];
        if ($match !== null) {
            $logs = $repo->logsForMatch($matchId);
        }

        $maps = [];
        foreach ($logs as $log) {
            $maps[] = [
                'log_id' => $log['log_id'],
                'date' => $log['date'],
                'red_score' => $log['red_score'],
                'blue_score' => $log['blue_score'],
                'blacklisted' => $log['blacklisted'],
                'scoreboard' => $this->buildScoreboard($log['log_id']),
            ];
        }

        return view('overlays.match', [
            'background' => $this->background(),
            'match' => $match ? (array) $match : [],
            'maps' => $maps,
        ]);
    }

    /**
     * Stats officielles agrégées d'un joueur par catégorie.
     * GET /overlay/joueur/{steamid}  (steamid64)
     */
    public function joueur(string $steamid): View
    {
        $steamid3 = SteamId::toSteamId3($steamid);
        $player = DB::table('players_info')->where('steamid', $steamid3)->first();
        $repo = new OfficialLogsRepository;

        $stats = [
            '9v9' => $repo->officialPlayerStats($steamid3, '9v9'),
            '6s' => $repo->officialPlayerStats($steamid3, '6s'),
        ];

        return view('overlays.joueur', [
            'background' => $this->background(),
            'player' => $player ? (array) $player : ['steamid' => $steamid3, 'name' => $steamid, 'avatar' => null],
            'steamid64' => $steamid,
            'stats' => $stats,
        ]);
    }

    /**
     * Scoreboard complet d'un log officiel (per camp).
     * GET /overlay/scoreboard/{logId}
     */
    public function scoreboard(int $logId): View
    {
        $log = (new OfficialLogsRepository)->infoFor($logId);

        return view('overlays.scoreboard', [
            'background' => $this->background(),
            'logId' => $logId,
            'category' => $log['category'] ?? '9v9',
            'scoreboard' => $this->buildScoreboard($logId),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Perfs des joueurs d'un log, partitionnées par camp (red/blue).
     *
     * @return array{red: array, blue: array}
     */
    private function buildScoreboard(int $logId): array
    {
        $rows = DB::table('player_matches as pm')
            ->leftJoin('players_info as pi', 'pi.steamid', '=', 'pm.steamid')
            ->where('pm.match_id', $logId)
            ->orderByDesc('pm.dmg')
            ->select(
                'pm.steamid', 'pm.team', 'pm.kills', 'pm.deaths', 'pm.dmg',
                'pm.assists', 'pm.heal', 'pm.airshots', 'pm.captures', 'pm.dapm',
                'pi.name', 'pi.avatar', 'pi.display_name',
            )
            ->get()
            ->all();

        $score = DB::table('match_scores')->where('match_id', $logId)->first();
        $redScore = $score ? (int) $score->red_score : null;
        $blueScore = $score ? (int) $score->blue_score : null;

        $red = [];
        $blue = [];
        foreach ($rows as $row) {
            $entry = [
                'name' => (string) ($row->display_name ?? $row->name ?? $row->steamid),
                'avatar' => $row->avatar,
                'kills' => (int) $row->kills,
                'deaths' => (int) $row->deaths,
                'dmg' => (int) $row->dmg,
                'assists' => (int) $row->assists,
                'heal' => (int) $row->heal,
                'airshots' => (int) $row->airshots,
                'captures' => (int) $row->captures,
                'dapm' => (int) $row->dapm,
                'kd' => (int) $row->deaths > 0 ? round((int) $row->kills / (int) $row->deaths, 2) : (int) $row->kills,
            ];
            if (($row->team ?? '') === 'blue') {
                $blue[] = $entry;
            } else {
                $red[] = $entry;
            }
        }

        return compact('red', 'blue', 'redScore', 'blueScore');
    }

    /**
     * Informations d'affichage d'une équipe (ETF2L ou équipe de France).
     *
     * @return array<string, mixed>
     */
    private function teamInfo(int $teamId): array
    {
        $config = config('hlfr.france_teams', []);
        $france6s = array_map('intval', (array) ($config['6v6'] ?? []));
        $franceHl = array_map('intval', (array) ($config['highlander'] ?? []));

        if (in_array($teamId, $france6s, true)) {
            return ['team_id' => $teamId, 'name' => 'Équipe de France 6v6', 'tag' => 'FR', 'is_france' => true, 'category' => '6s'];
        }
        if (in_array($teamId, $franceHl, true)) {
            return ['team_id' => $teamId, 'name' => 'Équipe de France Highlander', 'tag' => 'FR', 'is_france' => true, 'category' => '9v9'];
        }

        $team = DB::table('etf2l_teams')->where('team_id', $teamId)->first();

        return [
            'team_id' => $teamId,
            'name' => $team ? (string) $team->name : 'Équipe #'.$teamId,
            'tag' => $team ? (string) ($team->tag ?? '') : '',
            'is_france' => false,
            'category' => null,
        ];
    }

    /**
     * Roster d'une équipe : etf2l_players pour les équipes ETF2L,
     * france_national_players pour les équipes de France.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamRoster(int $teamId): array
    {
        $team = $this->teamInfo($teamId);
        $roster = [];

        if ($team['is_france']) {
            $rows = DB::table('france_national_players')
                ->where('team_id', $teamId)
                ->get()
                ->all();
            foreach ($rows as $row) {
                $steamid3 = (string) $row->steamid;
                $roster[] = ['steamid' => $steamid3, 'name' => null, 'avatar' => null];
            }
        } else {
            $rows = DB::table('etf2l_players')
                ->where('team_id', $teamId)
                ->whereNotNull('steamid64')
                ->get()
                ->all();
            foreach ($rows as $row) {
                if (empty($row->steamid64)) {
                    continue;
                }
                $roster[] = [
                    'steamid' => SteamId::toSteamId3((string) $row->steamid64),
                    'name' => (string) ($row->name ?? ''),
                    'avatar' => null,
                ];
            }
        }

        if ($roster !== []) {
            $steamids = array_column($roster, 'steamid');
            $infos = DB::table('players_info')
                ->whereIn('steamid', $steamids)
                ->get()
                ->keyBy('steamid');

            foreach ($roster as &$player) {
                $info = $infos->get($player['steamid']);
                if ($info !== null) {
                    $player['name'] = (string) ($info->display_name ?? $info->name ?? $player['name']);
                    $player['avatar'] = $info->avatar;
                }
            }
            unset($player);
        }

        return $roster;
    }

    /**
     * Fond sombre ou transparent (?bg=transparent).
     */
    private function background(): array
    {
        $bg = strtolower((string) (request()->query('bg') ?? 'dark'));

        return ['transparent' => $bg === 'transparent', 'dark' => $bg !== 'transparent'];
    }
}
