<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\MatchFormat;
use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

final class Etf2lRepository
{
    /**
     * Prochains matchs des équipes françaises, du plus proche au plus lointain.
     *
     * @return array<int, array<string, mixed>>
     */
    public function upcomingMatches(int $limit = 5): array
    {
        return DB::table('etf2l_matches')
            ->where('match_date', '>=', time())
            ->orderBy('match_date')
            ->limit($limit)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Matchs terminés depuis moins de $hours heures (fenêtre de 48 h par
     * défaut), du plus récent au plus ancien : résultats encore affichés
     * sur la page d'accueil.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentlyFinishedMatches(int $hours = 48, int $limit = 5): array
    {
        return DB::table('etf2l_matches')
            ->where('match_date', '<', time())
            ->where('match_date', '>=', time() - $hours * 3600)
            ->orderByDesc('match_date')
            ->orderByDesc('match_id')
            ->limit($limit)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Matchs ETF2L pour le sitemap (id + horodatage).
     *
     * @return array<int, array<string, mixed>>
     */
    public function sitemapMatches(): array
    {
        return DB::table('etf2l_matches')
            ->select('match_id', 'match_date')
            ->orderBy('match_date')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Matchs passés des équipes FR, du plus récent au plus ancien (paginé).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pastMatches(int $limit = 20, int $offset = 0): array
    {
        return DB::table('etf2l_matches')
            ->where('match_date', '<', time())
            ->orderByDesc('match_date')
            ->orderByDesc('match_id')
            ->skip($offset)
            ->take($limit)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Nombre total de matchs passés des équipes FR.
     */
    public function countPastMatches(): int
    {
        return (int) DB::table('etf2l_matches')->where('match_date', '<', time())->count();
    }

    /**
     * Niveaux calculés d'un joueur (division moyenne par mode de jeu).
     *
     * @return array<int, array<string, mixed>>
     */
    public function playerLevels(string $steamid3): array
    {
        return DB::table('player_levels')
            ->where('steamid', $steamid3)
            ->orderBy('game_mode')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Palmarès ETF2L d'un joueur : classements finaux et playoffs significatifs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function playerPalmares(string $steamid3): array
    {
        return DB::table('player_palmares')
            ->where('steamid', $steamid3)
            ->orderByDesc('season_time')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Détail d'un match ETF2L avec le roster des deux équipes.
     */
    public function etf2lMatchDetail(int $matchId): ?array
    {
        $match = DB::table('etf2l_matches')->where('match_id', $matchId)->first();

        if ($match === null) {
            return null;
        }

        $match = (array) $match;

        $teams = [];
        $steamid64s = [];

        foreach ([1, 2] as $n) {
            $teamId = (int) ($match['team' . $n . '_id'] ?? 0);

            $teamRow = DB::table('etf2l_teams')->where('team_id', $teamId)->first();

            // Équipe absente de la table (roster indisponible, ex. historique) :
            // on s'appuie sur les infos du match pour toujours afficher les deux camps.
            if ($teamRow === null) {
                $team = [
                    'team_id' => $teamId,
                    'name' => $n === 1 ? ($match['team1_name'] ?? 'TBD') : ($match['team2_name'] ?? 'TBD'),
                    'country' => $n === 1 ? ($match['team1_country'] ?? 'unknown') : ($match['team2_country'] ?? 'unknown'),
                    'tag' => null,
                ];
            } else {
                $team = (array) $teamRow;
            }

            if ($teamId > 0) {
                $players = DB::table('etf2l_players')->where('team_id', $teamId)->get()->all();
                $team['players'] = $this->sortPlayers(array_map(static fn ($p): array => (array) $p, $players));
            } else {
                $team['players'] = [];
            }

            foreach ($team['players'] as $p) {
                if (!empty($p['steamid64'])) {
                    $steamid64s[$p['steamid64']] = true;
                }
            }

            $team['key'] = 'team' . $n;
            $team['side'] = $n === 1 ? $match['team1_name'] : $match['team2_name'];
            $teams[] = $team;
        }

        $sitePlayers = $this->existingOnSite(array_keys($steamid64s));

        foreach ($teams as &$team) {
            foreach ($team['players'] as &$p) {
                $steamid64 = !empty($p['steamid64']) ? $p['steamid64'] : null;
                $p['steamid64'] = $steamid64;
                $p['exists_on_site'] = (bool) ($steamid64 !== null && isset($sitePlayers[$steamid64]));
                $p['profile_url'] = $p['exists_on_site']
                    ? '/profile/' . $steamid64
                    : 'https://etf2l.org/forum/user/' . (int) $p['player_id'] . '/';
            }
            unset($p);
        }
        unset($team);

        return [
            'match' => $match,
            'teams' => $teams,
            'maps' => $this->buildMaps($match),
        ];
    }

    /**
     * Construit la liste des cartes avec leurs scores (par carte et global).
     */
    private function buildMaps(array $match): array
    {
        $maps = json_decode((string) ($match['maps'] ?? 'null'), true) ?? [];
        $results = json_decode((string) ($match['map_results'] ?? 'null'), true) ?? [];
        if (!is_array($maps)) {
            $maps = [];
        }
        if (!is_array($results)) {
            $results = [];
        }
        $resultsByOrder = [];
        foreach ($results as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (isset($r['match_order'])) {
                $resultsByOrder[(int) $r['match_order']] = $r;
            }
        }
        $hasExplicitOrder = $resultsByOrder !== [];
        $isZeroIndexed = isset($resultsByOrder[0]);
        $r1 = isset($match['r1']) && $match['r1'] !== null ? (int) $match['r1'] : null;
        $r2 = isset($match['r2']) && $match['r2'] !== null ? (int) $match['r2'] : null;
        $isForfeit = $results === [] && ($r1 !== null || $r2 !== null) && $maps !== [];

        $list = [];
        foreach ($maps as $i => $map) {
            $order = $i + 1;
            $result = null;
            if ($hasExplicitOrder) {
                $result = $isZeroIndexed ? ($resultsByOrder[$i] ?? null) : ($resultsByOrder[$order] ?? null);
                if ($result === null && isset($results[$i]) && is_array($results[$i]) && !isset($results[$i]['match_order'])) {
                    $result = $results[$i];
                }
            } elseif (isset($results[$i]) && is_array($results[$i])) {
                $result = $results[$i];
            }

            $entry = [
                'order' => $order,
                'map' => (string) $map,
                'map_display' => MatchFormat::mapDisplay((string) $map),
            ];

            if ($result !== null) {
                $c1 = $result['clan1'] ?? $result['score1'] ?? $result['team1'] ?? null;
                $c2 = $result['clan2'] ?? $result['score2'] ?? $result['team2'] ?? null;
                if ($c1 !== null || $c2 !== null) {
                    $entry['team1'] = (int) ($c1 ?? 0);
                    $entry['team2'] = (int) ($c2 ?? 0);
                    $entry['golden_cap'] = (bool) ($result['golden_cap'] ?? $result['goldenCap'] ?? false);
                } elseif ($isForfeit) {
                    $entry['forfeit'] = true;
                }
            } elseif ($isForfeit) {
                $entry['forfeit'] = true;
            }

            $list[] = $entry;
        }

        return [
            'maps' => $list,
            'r1' => $r1,
            'r2' => $r2,
            'is_forfeit' => $isForfeit,
        ];
    }

    /**
     * Trie les joueurs d'une équipe : les Leaders (et assimilés) d'abord, puis
     * le reste par ordre alphabétique.
     */
    private function sortPlayers(array $players): array
    {
        usort($players, static function (array $a, array $b): int {
            $ra = (strtolower((string) ($a['role'] ?? '')) === 'leader') ? 0 : 1;
            $rb = (strtolower((string) ($b['role'] ?? '')) === 'leader') ? 0 : 1;

            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $players;
    }

    /**
     * @return array<string, bool> map steamid64 => true pour les joueurs présents sur le site.
     */
    private function existingOnSite(array $steamid64s): array
    {
        if ($steamid64s === []) {
            return [];
        }

        $steamid3s = array_map([SteamId::class, 'toSteamId3'], $steamid64s);

        $rows = DB::table('players_info')->whereIn('steamid', $steamid3s)->pluck('steamid');

        $map = [];
        foreach ($rows as $steamid3) {
            $steamid64 = SteamId::toSteamId64($steamid3);
            if ($steamid64 !== null) {
                $map[$steamid64] = true;
            }
        }

        return $map;
    }
}
