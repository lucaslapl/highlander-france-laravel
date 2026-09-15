<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Découverte de logs logs.tf via l'API tf2esports, en complément de la
 * recherche logs.tf par steamid (TeamStatsService::discoverLogs).
 *
 * Le lien entre les deux mondes est l'ID de match ETF2L : chaque résultat
 * ETF2L (clé `result`) correspond à une page etf2l.org/matches/{id}, que
 * tf2esports expose dans `league_match_url`. On croise donc :
 *   1. les matchs de l'équipe dans tf2esports (filtrés par format, statut et
 *      date minimale) dont le league_match_url matche un résultat ETF2L connu ;
 *   2. les joueurs du roster résolus vers leur id tf2esports via le
 *      leaderboard (format + ligue) ;
 *   3. les `recent` de /players/{id}/stats dont le match_id appartient à
 *      l'équipe → on collecte leur `logs_id` (le log logs.tf associé).
 *
 * Best-effort : toute erreur (key absente, 401/403/404/429, joueur non tracké)
 * renvoie une liste vide sans lever d'exception. Le filtre par match_id
 * évite les faux positifs (un joueur résolu à tort ne produit aucun log si
 * ses matchs récents ne recouvrent pas ceux de l'équipe).
 */
final class Tf2EsportsDiscovery
{
    public function __construct(private readonly Tf2EsportsApi $api) {}

    /**
     * Logs logs.tf découverts via tf2esports pour l'équipe.
     *
     * @param  string  $teamName  nom ETF2L de l'équipe (résolution des joueurs)
     * @param  array<int, array<string, mixed>>  $results  résultat de TeamStatsService::fetchResults()
     * @param  array<int, array<string, mixed>>  $players  roster [{name, role, steamid64}]
     * @return array<int, array<string, mixed>> [{id, title, map, date, players, mode, source}]
     */
    public function discoverLogs(string $teamName, array $results, array $players): array
    {
        $etf2lMatchIds = [];
        foreach ($results as $r) {
            $matchId = (int) ($r['result'] ?? 0);
            if ($matchId > 0) {
                $etf2lMatchIds[$matchId] = true;
            }
        }
        $etf2lMatchIds = array_keys($etf2lMatchIds);
        if ($etf2lMatchIds === []) {
            return [];
        }

        $teamMatches = $this->findTeamMatches($etf2lMatchIds, $results);
        if ($teamMatches === []) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($this->resolvePlayerIds($teamName, $players) as $playerId) {
            $stats = $this->safeStats((int) $playerId);
            foreach (($stats['recent'] ?? []) as $entry) {
                $matchId = (string) ($entry['match_id'] ?? '');
                if ($matchId === '' || ! isset($teamMatches[$matchId])) {
                    continue;
                }

                $logsId = (int) ($entry['logs_id'] ?? 0);
                if ($logsId <= 0 || isset($seen[$logsId])) {
                    continue;
                }

                $match = $teamMatches[$matchId];
                $mode = stripos((string) ($match['format'] ?? ''), 'Highlander') !== false ? '9v9' : '6s';
                $seen[$logsId] = true;

                $out[] = [
                    'id' => $logsId,
                    'title' => (string) ($match['event_name'] ?? 'tf2esports'),
                    'map' => '',
                    'date' => (int) strtotime((string) ($entry['played_at'] ?? '')),
                    'players' => $mode === '9v9' ? 9 : 6,
                    'mode' => $mode,
                    'source' => 'tf2esports',
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => ($b['date'] ?? 0) <=> ($a['date'] ?? 0));

        return $out;
    }

    /**
     * Matchs tf2esports de l'équipe, croisés par league_match_url.
     *
     * @param  int[]  $etf2lMatchIds
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, array<string, mixed>> id tf2esports (chaîne) => match
     */
    private function findTeamMatches(array $etf2lMatchIds, array $results): array
    {
        $formats = [];
        $dates = [];
        foreach ($results as $r) {
            if (! in_array((int) ($r['result'] ?? 0), $etf2lMatchIds, true)) {
                continue;
            }

            $type = mb_strtolower((string) ($r['competition']['type'] ?? ''));
            $formats[stripos($type, '6v6') !== false ? '6v6' : 'Highlander'] = true;

            $playedAt = (int) ($r['time'] ?? 0);
            if ($playedAt > 0) {
                $dates[] = $playedAt;
            }
        }

        $since = $dates !== [] ? date('Y-m-d', min($dates) - 86400) : null;

        $found = [];
        foreach (array_keys($formats) as $format) {
            $params = ['format' => $format, 'status' => 'Completed', 'limit' => 100];
            if ($since !== null) {
                $params['since'] = $since;
            }

            for ($page = 1; ; $page++) {
                $params['page'] = $page;
                $data = $this->safeMatches($params);
                $matches = $data['matches'] ?? [];

                foreach ($matches as $m) {
                    $url = (string) ($m['league_match_url'] ?? '');
                    if (preg_match('#etf2l\.org/matches/(\d+)#i', $url, $mm) === 1
                        && in_array((int) $mm[1], $etf2lMatchIds, true)) {
                        $found[(string) ($m['id'] ?? '')] = $m;
                    }
                }

                if (count($matches) < 100) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * IDs tf2esports des joueurs du roster (résolution par nom, leaderboard).
     *
     * @return string[]
     */
    private function resolvePlayerIds(string $teamName, array $players): array
    {
        $names = [];
        foreach ($players as $p) {
            $name = (string) ($p['name'] ?? '');
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        if ($names === []) {
            return [];
        }

        $ids = [];
        $teamKey = mb_strtolower(trim($teamName));
        foreach (['Highlander', '6v6'] as $format) {
            $leaderboard = $this->safeLeaderboard(['format' => $format, 'region' => 'ETF2L', 'metric' => 'kd', 'limit' => 100]);
            foreach (($leaderboard['rows'] ?? []) as $row) {
                $rowName = (string) ($row['player_name'] ?? '');
                if ($rowName === '' || ! isset($names[$rowName])) {
                    continue;
                }

                // Préférence : équipe alignée ; sinon nom seul (filtré ensuite
                // par match_id, donc peu risqué).
                $rowTeam = (string) ($row['team_name'] ?? '');
                if ($teamKey !== '' && mb_strtolower(trim($rowTeam)) !== $teamKey && $rowTeam !== '') {
                    continue;
                }

                $ids[(string) ($row['player_id'] ?? '')] = true;
            }
        }

        return array_keys($ids);
    }

    private function safeLeaderboard(array $params): array
    {
        try {
            return $this->api->leaderboard($params);
        } catch (\Throwable) {
            return [];
        }
    }

    private function safeMatches(array $params): array
    {
        try {
            return $this->api->matches($params);
        } catch (\Throwable) {
            return [];
        }
    }

    private function safeStats(int $playerId): array
    {
        try {
            return $this->api->playerStats($playerId);
        } catch (\Throwable) {
            return [];
        }
    }
}
