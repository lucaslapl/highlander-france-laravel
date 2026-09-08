<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OfficialMatchRepository;
use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

/**
 * Récupération de l'historique officiel ETF2L (via l'API v2 couchée sur
 * etf2l_api_cache) et synchronisation des rosters équipes.
 *
 * - Résultats d'un joueur : /player/{id64}/results (historisés dans
 *   player_season_matches, catégories « Season » uniquement).
 * - Fiche équipe + roster : /team/{id} (etf2l_teams / etf2l_players).
 */
final class Etf2lHistoryService
{
    private const API_CALL_DELAY_S = 1.1;

    private const HTTP_TIMEOUT_S = 15;

    /** TTL du cache des résultats joueurs / fiches équipes. */
    private const CACHE_TTL = 7 * 86400;

    private const RESULTS_PER_PAGE = 50;

    /** Nombre maximal de pages de résultats par joueur (garde-fou). */
    private const MAX_RESULTS_PAGES = 30;

    /** Pages de résultats récupérées en parallèle (bonne citoyenneté API). */
    private const CONCURRENCY = 2;

    private const SEASON_CATEGORIES = [
        'Highlander Season',
        '6v6 Season',
    ];

    private float $lastHttpAt = 0.0;

    private \PDO $db;

    private OfficialMatchRepository $repo;

    public function __construct(?OfficialMatchRepository $repo = null)
    {
        $this->db = DB::connection()->getPdo();
        $this->repo = $repo ?? new OfficialMatchRepository;
    }

    // ─── HTTP / cache ───────────────────────────────────────────────────────

    private function cachedGet(string $url, bool $force = false): array
    {
        if (! $force) {
            $stmt = $this->db->prepare('SELECT payload FROM etf2l_api_cache WHERE url = ? AND fetched_at > ?');
            $stmt->execute([$url, time() - self::CACHE_TTL]);
            $payload = $stmt->fetchColumn();
            if (is_string($payload) && $payload !== '') {
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $elapsed = microtime(true) - $this->lastHttpAt;
        if ($this->lastHttpAt > 0 && $elapsed < self::API_CALL_DELAY_S) {
            usleep((int) ((self::API_CALL_DELAY_S - $elapsed) * 1e6));
        }
        $this->lastHttpAt = microtime(true);

        $data = $this->fetchWithRetry($url);

        $isMysql = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT INTO etf2l_api_cache (url, payload, fetched_at) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)'
            : 'INSERT INTO etf2l_api_cache (url, payload, fetched_at) VALUES (?, ?, ?)
               ON CONFLICT(url) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at';

        $this->db->prepare($sql)->execute([$url, json_encode($data, JSON_THROW_ON_ERROR), time()]);

        return $data;
    }

    private function fetchWithRetry(string $url, int $attempts = 3): array
    {
        $backoffs = [0, 5, 20];
        $lastError = 'raison inconnue';

        for ($i = 1; $i <= $attempts; $i++) {
            if ($i > 1) {
                sleep($backoffs[min($i - 1, count($backoffs) - 1)]);
            }

            $meta = JsonClient::getWithMeta($url, self::HTTP_TIMEOUT_S, 'Highlander France Bot/1.0', ['Accept: application/json']);

            if ($meta['curl_error'] !== '') {
                $lastError = 'erreur cURL : '.$meta['curl_error'];

                continue;
            }

            if (! is_array($meta['data'])) {
                $lastError = 'HTTP '.$meta['http_code'].' avec réponse non-JSON';

                continue;
            }

            $code = isset($meta['data']['status']['code']) ? (int) $meta['data']['status']['code'] : null;

            if ($code === null || $code === 200) {
                return $meta['data'];
            }

            if ($code === 404) {
                return [];
            }

            if (! in_array($code, [429, 500, 502, 503, 504], true)) {
                throw new \RuntimeException("L'API ETF2L a répondu négativement pour {$url} : HTTP {$code}");
            }

            $lastError = 'HTTP '.$code.' (réponse transitoire)';
        }

        throw new \RuntimeException("Appel API ETF2L impossible après {$attempts} tentatives ({$url}) : ".$lastError);
    }

    // ─── Équipes / rosters ──────────────────────────────────────────────────

    /**
     * Synchronise la fiche d'une équipe ETF2L et son roster actuel.
     *
     * @return array{team: array<string, mixed>|null, players: array<int, array<string, mixed>>}
     */
    public function syncTeam(int $teamId, bool $force = false): array
    {
        $response = $this->cachedGet('https://api-v2.etf2l.org/team/'.$teamId, $force);

        if (! isset($response['status']['code']) || (int) $response['status']['code'] !== 200) {
            return ['team' => null, 'players' => []];
        }

        $team = $response['team'] ?? null;
        if (! is_array($team)) {
            return ['team' => null, 'players' => []];
        }

        $teamRow = [
            'team_id' => $teamId,
            'name' => (string) ($team['name'] ?? 'TBD'),
            'country' => isset($team['country']) ? strtolower((string) $team['country']) : 'unknown',
            'tag' => $team['tag'] ?? null,
        ];
        DB::table('etf2l_teams')->upsert($teamRow, ['team_id'], ['name', 'country', 'tag']);

        // Roster : on remplace l'ancien (les joueurs partis disparaissent).
        $players = [];
        foreach (($team['players'] ?? []) as $p) {
            if (! isset($p['steam']['id64'])) {
                continue;
            }
            $steamid64 = (string) $p['steam']['id64'];
            if (! preg_match('/^\d{17}$/', $steamid64)) {
                continue;
            }

            $players[] = [
                'team_id' => $teamId,
                'player_id' => (int) ($p['id'] ?? 0),
                'name' => $p['name'] ?? 'Joueur ETF2L',
                'role' => $p['role'] ?? 'Member',
                'country' => isset($p['country']) ? strtolower((string) $p['country']) : 'unknown',
                'steamid64' => $steamid64,
                'steamid' => SteamId::toSteamId3($steamid64),
            ];
        }

        if ($players !== []) {
            DB::transaction(function () use ($teamId, $players): void {
                DB::table('etf2l_players')->where('team_id', $teamId)->delete();
                DB::table('etf2l_players')->insert(
                    array_map(static fn (array $p): array => \Illuminate\Support\Arr::except($p, ['steamid']), $players),
                );
            });
        }

        return ['team' => $teamRow, 'players' => $players];
    }

    /**
     * Roster actuel d'une équipe (steamid3) depuis etf2l_players.
     *
     * @return string[]
     */
    public function rosterSteamids(int $teamId): array
    {
        return DB::table('etf2l_players')
            ->where('team_id', $teamId)
            ->whereNotNull('steamid64')
            ->pluck('steamid64')
            ->map(static fn ($id): ?string => SteamId::toSteamId3((string) $id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Mode de jeu majoritaire d'une équipe (9v9/6s) d'après son historique
     * officiel stocké, ou null s'il est indéterminé.
     */
    public function detectMode(int $teamId): ?string
    {
        $rows = DB::table('player_season_matches')
            ->where('team_id', $teamId)
            ->select('game_mode', DB::raw('COUNT(*) AS cnt'))
            ->groupBy('game_mode')
            ->orderByDesc('cnt')
            ->get();

        $best = $rows->first();
        if ($best === null) {
            return null;
        }

        $mode = (string) $best->game_mode;

        return in_array($mode, ['9v9', '6s'], true) ? $mode : null;
    }

    // ─── Historique joueur ──────────────────────────────────────────────────

    /**
     * Récupère et stocke l'historique officiel (catégories Season) d'un joueur.
     *
     * @param  callable(int):void|null  $onPage  Appelé après chaque page avec son n° (progression).
     * @return array{fetched: int, stored: int}
     */
    public function refreshPlayer(string $steamid3, bool $force = false, ?callable $onPage = null): array
    {
        $steamid64 = SteamId::toSteamId64($steamid3);
        if ($steamid64 === null) {
            return ['fetched' => 0, 'stored' => 0];
        }

        $results = $this->fetchPlayerResults($steamid64, $force, $onPage);

        [$matches, $seasons] = $this->normalizeHistory($steamid3, $results);

        foreach ($seasons as $season) {
            $this->repo->upsertSeason($season);
        }

        if ($matches !== []) {
            // On remplace l'historique stocké (le cache API suffit pour éviter
            // de revenir sur l'API à chaque page).
            $this->repo->deletePlayerHistory($steamid3);
            $this->repo->insertPlayerMatches($steamid3, $matches);
        }

        return ['fetched' => count($results), 'stored' => count($matches)];
    }

    /**
     * Récupère les résultats en paginant par petits lots parallèles (les pages
     * sont indépendantes) et s'arrête dès que les 3 dernières saisons de chaque
     * catégorie ont été couvertes (les résultats sont triés du plus récent au
     * plus ancien, donc toute page ultérieure serait obsolète pour la fenêtre).
     *
     * @param  callable(int):void|null  $onPage  Appelé après chaque page traitée.
     * @return array<int, array<string, mixed>> Résultats bruts (toutes pages).
     */
    private function fetchPlayerResults(string $steamid64, bool $force, ?callable $onPage = null): array
    {
        $out = [];
        $coveredSeasons = [];
        $page = 1;
        $lastPage = null;
        $cap = self::MAX_RESULTS_PAGES;

        while ($page <= $cap) {
            $maxThisBatch = $lastPage !== null ? min($lastPage, $cap) : $cap;
            $pKeys = [];
            $urlMap = [];
            for ($k = 0; $k < self::CONCURRENCY && $page + $k <= $maxThisBatch; $k++) {
                $p = $page + $k;
                $urlMap['p'.$p] = 'https://api-v2.etf2l.org/player/'.$steamid64.'/results?limit='.self::RESULTS_PER_PAGE.'&page='.$p;
                $pKeys[] = $p;
            }
            if ($pKeys === []) {
                break;
            }

            $responses = count($pKeys) <= 1
                ? ['p'.$pKeys[0] => $this->cachedGet($urlMap['p'.$pKeys[0]], $force)]
                : $this->cachedGetBatch($urlMap, $force);

            $allEmpty = true;
            foreach ($pKeys as $pp) {
                $response = $responses['p'.$pp] ?? [];
                $pageResults = $response['data'] ?? [];
                if (is_array($pageResults) && $pageResults !== []) {
                    $allEmpty = false;
                    array_push($out, ...$pageResults);
                    $this->noteCoveredSeasons($pageResults, $coveredSeasons);
                }
                if (is_array($response) && isset($response['last_page'])) {
                    $lastPage = (int) $response['last_page'];
                }
                if ($onPage !== null) {
                    $onPage($pp);
                }
            }

            $this->pruneToCovered($out, $coveredSeasons);
            if ($allEmpty || $this->hasThreeSeasonsPerCategory($coveredSeasons)) {
                break;
            }
            if ($lastPage !== null && $page >= $lastPage) {
                break;
            }

            $page += count($pKeys);
        }

        return $out;
    }

    /**
     * Supprime de la sortie les résultats plus anciens que les 3 dernières
     * saisons déjà connues de chaque catégorie présente.
     *
     * @param  array<int, array<string, mixed>>  $out
     * @param  array<string, array<string, true>>  $covered
     */
    private function pruneToCovered(array &$out, array $covered): void
    {
        if ($covered === []) {
            return;
        }
        $out = array_values(array_filter($out, static function (array $r) use ($covered): bool {
            $comp = $r['competition'] ?? null;
            if (! is_array($comp)) {
                return true;
            }
            $cat = (string) ($comp['category'] ?? '');
            $labels = $covered[$cat] ?? null;
            if ($labels === null) {
                return true;
            }
            $name = (string) ($comp['name'] ?? '');
            if (! preg_match('/season\s+(\d+)/i', $name, $m)) {
                return true;
            }
            $low = (int) $m[1];
            foreach (array_keys($labels) as $known) {
                if ($low >= (int) $known) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Mémorise les numéros de saison (par catégorie) présents sur une page.
     *
     * @param  array<int, array<string, mixed>>  $pageResults
     * @param  array<string, array<string, true>>  $covered
     */
    private function noteCoveredSeasons(array $pageResults, array &$covered): void
    {
        foreach ($pageResults as $r) {
            $comp = $r['competition'] ?? null;
            if (! is_array($comp)) {
                continue;
            }
            $cat = (string) ($comp['category'] ?? '');
            if (! in_array($cat, self::SEASON_CATEGORIES, true)) {
                continue;
            }
            $name = (string) ($comp['name'] ?? '');
            if (preg_match('/season\s+(\d+)/i', $name, $m)) {
                $covered[$cat][$m[1]] = true;
            }
        }
    }

    /**
     * Vrai si chaque catégorie présente a déjà couvert au moins 3 saisons.
     *
     * @param  array<string, array<string, true>>  $covered
     */
    private function hasThreeSeasonsPerCategory(array $covered): bool
    {
        if ($covered === []) {
            return false;
        }
        foreach ($covered as $labels) {
            if (count($labels) < 3) {
                return false;
            }
        }

        return true;
    }

    /**
     * Récupère plusieurs URLs en parallèle (curl_multi), en respectant un même
     * intervalle de politesse entre les lots. Les URLs non cachées sont
     * récupérées puis stockées dans etf2l_api_cache.
     *
     * @param  array<string, string>  $urlMap  map clé => url
     * @return array<string, array<string, mixed>>
     */
    private function cachedGetBatch(array $urlMap, bool $force): array
    {
        $now = time();
        $result = [];
        $needCurl = [];

        foreach ($urlMap as $key => $url) {
            if (! $force) {
                $stmt = $this->db->prepare('SELECT payload FROM etf2l_api_cache WHERE url = ? AND fetched_at > ?');
                $stmt->execute([$url, $now - self::CACHE_TTL]);
                $payload = $stmt->fetchColumn();
                if (is_string($payload) && $payload !== '') {
                    $decoded = json_decode($payload, true);
                    if (is_array($decoded)) {
                        $result[$key] = $decoded;
                        continue;
                    }
                }
            }
            $needCurl[$key] = $url;
        }

        if ($needCurl !== []) {
            $elapsed = microtime(true) - $this->lastHttpAt;
            if ($this->lastHttpAt > 0 && $elapsed < self::API_CALL_DELAY_S) {
                usleep((int) ((self::API_CALL_DELAY_S - $elapsed) * 1e6));
            }
            $this->lastHttpAt = microtime(true);

            $fetched = $this->multiFetch($needCurl);
            foreach ($fetched as $key => $data) {
                if (is_array($data) && $data !== []) {
                    $result[$key] = $data;
                    $this->cachePut($needCurl[$key], $data);
                } else {
                    $result[$key] = [];
                }
            }
        }

        return $result;
    }

    /**
     * Exécute plusieurs cURL en parallèle. Chaque URL bloquante/transitoire
     * retombe sur fetchWithRetry (backoff 5/20 s) pour rester fiable.
     *
     * @param  array<string, string>  $urls  map clé => url
     * @return array<string, array<string, mixed>|array{}>
     */
    private function multiFetch(array $urls): array
    {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($urls as $key => $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_S,
                CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_S,
                CURLOPT_USERAGENT => 'Highlander France Bot/1.0',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $decoded = (is_string($body) && trim($body) !== '')
                ? json_decode($body, true)
                : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $code = is_array($decoded) && isset($decoded['status']['code']) ? (int) $decoded['status']['code'] : 0;
            if (is_array($decoded) && ($code === 0 || $code === 200)) {
                $out[$key] = $decoded;
                continue;
            }

            if (! is_array($decoded) || in_array($code, [429, 500, 502, 503, 504], true)) {
                $out[$key] = $this->fetchWithRetry($urls[$key]);
                continue;
            }
            $out[$key] = is_array($decoded) ? $decoded : [];
        }
        curl_multi_close($mh);

        return $out;
    }

    private function cachePut(string $url, array $data): void
    {
        $isMysql = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT INTO etf2l_api_cache (url, payload, fetched_at) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)'
            : 'INSERT INTO etf2l_api_cache (url, payload, fetched_at) VALUES (?, ?, ?)
               ON CONFLICT(url) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at';

        $this->db->prepare($sql)->execute([$url, json_encode($data, JSON_THROW_ON_ERROR), time()]);
    }

    /**
     * Normalise les résultats bruts en matchs (catégories Season) + compétitions.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function normalizeHistory(string $steamid3, array $results): array
    {
        $matches = [];
        $seasons = [];

        foreach ($results as $r) {
            $competition = $r['competition'] ?? null;
            if (! is_array($competition)) {
                continue;
            }

            $category = (string) ($competition['category'] ?? '');
            if (! in_array($category, self::SEASON_CATEGORIES, true)) {
                continue;
            }

            $compId = (int) ($competition['id'] ?? 0);
            $compName = (string) ($competition['name'] ?? '');
            $type = (string) ($competition['type'] ?? '');
            $mode = $this->gameModeFor($type, $compName);
            if ($mode === null || $compId <= 0) {
                continue;
            }

            [$teamId, $opponentId, $isClan1] = $this->teamsOfResult($r);
            if ($teamId <= 0) {
                $teamId = 0;
            }

            // Historique conservé pour toutes les équipes et les deux modes :
            // le cache est par joueur et réutilisé pour toute équipe demandée.
            if ($teamId > 0) {
                $matches[] = [
                    'match_id' => (int) ($r['id'] ?? 0),
                    'competition_id' => $compId,
                    'team_id' => $teamId,
                    'opponent_team_id' => $opponentId > 0 ? $opponentId : null,
                    'game_mode' => $mode,
                    'time' => isset($r['time']) ? (int) $r['time'] : null,
                    'round' => (string) ($r['round'] ?? ''),
                    'r1' => isset($r['r1']) ? (int) $r['r1'] : null,
                    'r2' => isset($r['r2']) ? (int) $r['r2'] : null,
                    'team_is_clan1' => $isClan1,
                ];
            }

            if (! isset($seasons[$compId])) {
                $seasons[$compId] = [
                    'competition_id' => $compId,
                    'name' => $compName,
                    'category' => $category,
                    'type' => $type !== '' ? $type : null,
                    'game_mode' => $mode,
                    'season_label' => $this->seasonLabel($compName),
                ];
            }
        }

        return [array_values($matches), array_values($seasons)];
    }

    /**
     * @return array{0: int, 1: int, 2: bool} [team_id, opponent_team_id, team_is_clan1]
     */
    private function teamsOfResult(array $result): array
    {
        $teamId = 0;
        $opponentId = 0;
        $isClan1 = true;

        foreach (['clan1', 'clan2'] as $i => $side) {
            $clan = $result[$side] ?? null;
            if (! is_array($clan)) {
                continue;
            }
            $id = (int) ($clan['id'] ?? 0);
            $wasInTeam = (bool) ($clan['was_in_team'] ?? false);
            if ($wasInTeam) {
                $teamId = $id;
                $isClan1 = $i === 0;
            } else {
                $opponentId = $id;
            }
        }

        return [$teamId, $opponentId, $isClan1];
    }

    private function gameModeFor(string $type, string $compName): ?string
    {
        if (stripos($compName, '6v6') !== false) {
            return '6s';
        }
        if (stripos($compName, 'Highlander') !== false) {
            return '9v9';
        }

        return match (strtolower($type)) {
            '6v6' => '6s',
            'highlander' => '9v9',
            default => null,
        };
    }

    private function seasonLabel(string $compName): ?string
    {
        if (preg_match('/season\s+(\d+)/i', $compName, $m)) {
            return $m[1];
        }

        return null;
    }
}
