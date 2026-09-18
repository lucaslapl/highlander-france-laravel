<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Préparation des stats d'une équipe (onglet « Équipe » de /admin/stats-joueur).
 *
 * Collecte, à la volée et en synchrone (petit volume), les données nécessaires
 * avant le lancement du calcul asynchrone :
 *  - le roster ETF2L (équipe + membres avec steamid64) via team/{id} ;
 *  - les résultats officiels ETF2L (team/{id}/results) regroupés par
 *    compétition/saison et par mode => winrate officiel par compétition ;
 *  - les logs logs.tf candidats, découverts via ?player=<steamid64,…>,
 *    classés par mode d'après leur titre.
 *
 * La récupération HTTP est injectable (closures) pour les tests sans réseau.
 * Les réponses sont mises en cache en JSON sous hlfr_data_path(). Aucun
 * modèle Eloquent : logique indépendante de la base.
 */
final class TeamStatsService
{
    /** Mapping type de compétition ETF2L => mode. */
    private const MODE_MAP = [
        'highlander' => '9v9',
        '6v6' => '6s',
    ];

    /** TTL (s) du cache des réponses ETF2L / logs.tf lors de la préparation. */
    private const CACHE_TTL_S = 300;

    /** Délai minimal entre deux appels HTTP réels (ETF2L & logs.tf : 60 req/min). */
    private const HTTP_DELAY_S = 1.1;

    private float $lastHttpAt = 0;

    /**
     * @param  \Closure(string): (array|null)|null  $etf2lFetcher  Récupère une réponse ETF2L brute
     * @param  \Closure(string): (array|null)|null  $logsFetcher  Récupère une réponse logs.tf brute
     */
    public function __construct(
        private readonly ?\Closure $etf2lFetcher = null,
        private readonly ?\Closure $logsFetcher = null,
    ) {}

    // ---------------------------------------------------------------
    // Détection du mode de jeu
    // ---------------------------------------------------------------

    /**
     * Mode (9v9 / 6s) d'une compétition ETF2L à partir de son type/catégorie/nom.
     *
     * @return string|null null si le mode est indéterminé
     */
    public static function gameModeFromCompetition(string $type, string $category, string $name): ?string
    {
        $typeLower = mb_strtolower($type);
        if (isset(self::MODE_MAP[$typeLower])) {
            return self::MODE_MAP[$typeLower];
        }

        // Types spécifiques aux équipes nationales (Nations' Cup).
        if (stripos($type, 'national') !== false) {
            if (stripos($type, '6v6') !== false) {
                return '6s';
            }
            if (stripos($type, 'highlander') !== false) {
                return '9v9';
            }
        }

        // Repli sur le nom lorsque le type générique est manquant/erroné.
        if (stripos($name, 'highlander') !== false) {
            return '9v9';
        }
        if (stripos($name, '6v6') !== false || stripos($name, '6s') !== false) {
            return '6s';
        }
        if (stripos($name, '9v9') !== false) {
            return '9v9';
        }

        return null;
    }

    /**
     * Mode (9v9 / 6s) d'un log logs.tf à partir de son titre (meilleur effort).
     * Repli sur 9v9 (la communauté est centrée Highlander).
     */
    public static function gameModeFromLogTitle(string $title): string
    {
        $lower = mb_strtolower($title);

        if (preg_match('/\[6s\]|\b6s\b|\b6v6\b/i', $lower) === 1) {
            return '6s';
        }

        if (preg_match('/\b9v9\b|\bhighlander\b|\bhl:/i', $lower) === 1) {
            return '9v9';
        }

        return '9v9';
    }

    // ---------------------------------------------------------------
    // Roster
    // ---------------------------------------------------------------

    /**
     * Roster d'une équipe ETF2L (équipe + membres dotés d'un steamid64).
     *
     * @return array<string, mixed>|null null si l'équipe est introuvable
     */
    public function fetchRoster(int $teamId): ?array
    {
        $data = $this->fetchEtf2l('https://api-v2.etf2l.org/team/'.$teamId, 7 * 86400);
        if (! is_array($data)) {
            return null;
        }

        if (! isset($data['status']['code']) || (int) $data['status']['code'] !== 200) {
            return null;
        }

        $team = $data['team'] ?? null;
        if (! is_array($team)) {
            return null;
        }

        $players = [];
        foreach (($team['players'] ?? []) as $member) {
            $steamid64 = $member['steam']['id64'] ?? null;
            if ($steamid64 === null || $steamid64 === '') {
                continue;
            }

            $players[] = [
                'player_id' => isset($member['id']) ? (int) $member['id'] : null,
                'steamid64' => (string) $steamid64,
                'name' => (string) ($member['name'] ?? 'Joueur ETF2L'),
                'role' => (string) ($member['role'] ?? 'Member'),
                'country' => isset($member['country']) ? mb_strtolower((string) $member['country']) : null,
            ];
        }

        return [
            'id' => $teamId,
            'name' => (string) ($team['name'] ?? 'Inconnue'),
            'tag' => isset($team['tag']) ? (string) $team['tag'] : null,
            'country' => isset($team['country']) ? mb_strtolower((string) $team['country']) : null,
            'players' => $players,
        ];
    }

    // ---------------------------------------------------------------
    // Résultats officiels + winrate par compétition
    // ---------------------------------------------------------------

    /**
     * Résultats officiels d'une équipe ETF2L (toutes pages).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchResults(int $teamId): array
    {
        $results = [];

        for ($page = 1; ; $page++) {
            $data = $this->fetchEtf2l('https://api-v2.etf2l.org/team/'.$teamId.'/results?limit=50&page='.$page, 3600);
            if (! is_array($data)) {
                break;
            }

            $pageResults = $data['data'] ?? [];
            if ($pageResults !== []) {
                $results[] = $pageResults;
            }

            $lastPage = (int) ($data['last_page'] ?? $page);
            if ($page >= $lastPage) {
                break;
            }
        }

        return $results === [] ? [] : array_merge(...$results);
    }

    /**
     * Derniers matchs d'une équipe ETF2L.
     *
     * L'API team/{id}/results renvoie une ligne par joueur du match : avec un
     * petit `limit` on n'obtiendrait qu'un seul match réel (50 lignes ≈ 2-3
     * matchs de 9v9). On pagine donc par lots de 100 lignes jusqu'à avoir
     * collecté $limit matchs uniques (ou la dernière page).
     *
     * @return array<int, array<string, mixed>> matchs normalisés, du plus récent au plus ancien
     */
    public function fetchRecentResults(int $teamId, int $limit = 5): array
    {
        $desired = max(1, min(100, $limit));
        $rows = [];
        $normalized = [];

        for ($page = 1; ; $page++) {
            $data = $this->fetchEtf2l(
                'https://api-v2.etf2l.org/team/'.$teamId.'/results?limit=100&page='.$page,
                3600
            );
            if (! is_array($data)) {
                break;
            }

            $pageResults = is_array($data['data'] ?? null) ? $data['data'] : [];
            if ($pageResults === []) {
                break;
            }
            $rows = array_merge($rows, $pageResults);

            $normalized = $this->normalizeTeamMatches($rows, $teamId);
            if (count($normalized) >= $desired) {
                break;
            }

            $lastPage = (int) ($data['last_page'] ?? $page);
            if ($page >= $lastPage) {
                break;
            }
        }

        return array_slice($normalized, 0, $desired);
    }

    /**
     * Métadonnées d'une équipe ETF2L pour la création d'une page équipe :
     * infos de base + division suggérée (compétition la plus récente dotée
     * d'une division reconnue, sinon la plus haute remplie).
     *
     * @return array<string, mixed>|null null si l'équipe est introuvable
     */
    public function fetchTeamMeta(int $teamId): ?array
    {
        $data = $this->fetchEtf2l('https://api-v2.etf2l.org/team/'.$teamId, 7 * 86400);
        if (! is_array($data)) {
            return null;
        }
        if (! isset($data['status']['code']) || (int) $data['status']['code'] !== 200) {
            return null;
        }

        $team = $data['team'] ?? null;
        if (! is_array($team)) {
            return null;
        }

        return [
            'id' => $teamId,
            'name' => (string) ($team['name'] ?? 'Inconnue'),
            'tag' => isset($team['tag']) ? (string) $team['tag'] : null,
            'country' => isset($team['country']) ? mb_strtolower((string) $team['country']) : null,
            'competitions' => (array) ($team['competitions'] ?? []),
            'suggested_division' => $this->suggestedDivision($team),
        ];
    }

    /**
     * Division la plus probable d'une équipe, d'après ses compétitions ETF2L.
     * Priorité à la saison la plus récente (numéro le plus élevé dans le nom),
     * puis au niveau le plus haut (tier le plus faible).
     */
    private function suggestedDivision(array $team): ?string
    {
        $map = (array) config('hlfr.etf2l_division_map', []);
        $best = null;

        foreach (($team['competitions'] ?? []) as $comp) {
            $div = $comp['division'] ?? null;
            $name = is_array($div) ? trim((string) ($div['name'] ?? '')) : '';
            if ($name === '' || ! isset($map[strtolower($name)])) {
                continue;
            }

            $season = $this->competitionSeason(
                (string) ($comp['competition'] ?? ''),
                (string) ($comp['category'] ?? '')
            );
            $tier = is_array($div) && isset($div['tier']) && $div['tier'] !== null ? (int) $div['tier'] : null;

            $candidate = [
                'has_season' => $season !== null ? 1 : 0,
                'season' => $season ?? 0,
                'tier' => $tier ?? 99,
                'division' => (string) $map[strtolower($name)],
            ];

            if (self::betterDivision($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best['division'] ?? null;
    }

    /**
     * Ordre de préférence entre deux candidats division (saison, puis tier).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>|null  $b
     */
    private static function betterDivision(array $a, ?array $b): bool
    {
        if ($b === null) {
            return true;
        }

        $ka = [$a['has_season'], (int) $a['season'], -((int) $a['tier'])];
        $kb = [$b['has_season'], (int) $b['season'], -((int) $b['tier'])];

        foreach ($ka as $i => $value) {
            if ($value !== $kb[$i]) {
                return $value > $kb[$i];
            }
        }

        return false;
    }

    /**
     * Numéro de saison extrait d'un nom/catégorie de compétition (ex. #9, Season 52).
     */
    private function competitionSeason(string $competition, string $category): ?int
    {
        foreach ([$competition, $category] as $label) {
            if (preg_match('/(?:#|\b(?:Season|S))[^\d]*(\d+)/i', $label, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Normalise les réponses brutes de team/{id}/results (une ligne par joueur)
     * en matchs uniques, avec scores relatifs à l'équipe demandée.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTeamMatches(array $results, int $teamId): array
    {
        $seen = [];
        $out = [];

        foreach ($results as $r) {
            $resultId = (int) ($r['result'] ?? 0);
            if ($resultId <= 0 || isset($seen[$resultId])) {
                continue;
            }
            $seen[$resultId] = true;

            $clan1 = $r['clan1'] ?? null;
            $clan2 = $r['clan2'] ?? null;
            $same1 = is_array($clan1) && (int) ($clan1['id'] ?? 0) === $teamId;
            $same2 = is_array($clan2) && (int) ($clan2['id'] ?? 0) === $teamId;
            if (! $same1 && ! $same2) {
                continue;
            }

            $r1 = isset($r['r1']) && $r['r1'] !== null ? (int) $r['r1'] : null;
            $r2 = isset($r['r2']) && $r['r2'] !== null ? (int) $r['r2'] : null;
            $ours = $same1 ? $r1 : $r2;
            $theirs = $same1 ? $r2 : $r1;
            $opponent = $same1 && is_array($clan2) ? $clan2 : (is_array($clan1) ? $clan1 : []);

            $out[] = [
                'match_id' => $resultId,
                'time' => (int) ($r['time'] ?? 0),
                'competition_name' => (string) ($r['competition']['name'] ?? ''),
                'category' => (string) ($r['competition']['category'] ?? ''),
                'round' => (string) ($r['round'] ?? ''),
                'maps' => is_array($r['maps'] ?? null) ? $r['maps'] : [],
                'team_id' => $teamId,
                'score_ours' => $ours,
                'score_theirs' => $theirs,
                'won' => $ours !== null && $theirs !== null && $ours !== $theirs ? $ours > $theirs : null,
                'opponent' => [
                    'id' => (int) ($opponent['id'] ?? 0),
                    'name' => (string) ($opponent['name'] ?? 'Adversaire'),
                    'country' => isset($opponent['country']) ? mb_strtolower((string) $opponent['country']) : null,
                ],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['time'] <=> $a['time'] ?: $b['match_id'] <=> $a['match_id']);

        return $out;
    }

    /**
     * Regroupe les résultats par compétition et par mode, avec le winrate officiel.
     *
     * @return array<string, array<int, array<string, mixed>>> mode => compétitions
     */
    public function groupCompetitions(int $teamId, array $results): array
    {
        $byComp = [];

        // Un même match ETF2L (clé `result`) est renvoyé une fois par joueur du
        // roster par l'API team/{id}/results : ne le compter qu'une seule fois.
        $seenResults = [];

        foreach ($results as $r) {
            $comp = $r['competition'] ?? null;
            if (! is_array($comp)) {
                continue;
            }

            $compId = (int) ($comp['id'] ?? 0);
            if ($compId <= 0) {
                continue;
            }

            $resultId = (int) ($r['result'] ?? 0);
            if ($resultId > 0) {
                if (isset($seenResults[$resultId])) {
                    continue;
                }
                $seenResults[$resultId] = true;
            }

            $compName = (string) ($comp['name'] ?? '');
            $mode = self::gameModeFromCompetition(
                (string) ($comp['type'] ?? ''),
                (string) ($comp['category'] ?? ''),
                $compName
            );
            if ($mode === null) {
                continue;
            }

            $clan1 = $r['clan1'] ?? null;
            $clan2 = $r['clan2'] ?? null;
            $isClan1 = is_array($clan1) && (int) ($clan1['id'] ?? 0) === $teamId;
            $isClan2 = is_array($clan2) && (int) ($clan2['id'] ?? 0) === $teamId;
            if (! $isClan1 && ! $isClan2) {
                continue;
            }

            if (! isset($byComp[$compId])) {
                $byComp[$compId] = [
                    'id' => $compId,
                    'name' => $compName,
                    'mode' => $mode,
                    'wins' => 0,
                    'losses' => 0,
                    'draws' => 0,
                    'total' => 0,
                ];
            }

            $byComp[$compId]['total']++;
            $r1 = (int) ($r['r1'] ?? 0);
            $r2 = (int) ($r['r2'] ?? 0);
            $mine = $isClan1 ? $r1 : $r2;
            $theirs = $isClan1 ? $r2 : $r1;

            if ($mine > $theirs) {
                $byComp[$compId]['wins']++;
            } elseif ($mine < $theirs) {
                $byComp[$compId]['losses']++;
            } else {
                $byComp[$compId]['draws']++;
            }
        }

        foreach ($byComp as &$comp) {
            $decided = $comp['wins'] + $comp['losses'];
            $comp['winrate'] = $decided > 0 ? (int) round($comp['wins'] * 100 / $decided, 0) : null;
        }
        unset($comp);

        // Tri : plus joué d'abord, puis alphabétique.
        usort($byComp, static fn (array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        $byMode = ['9v9' => [], '6s' => []];
        foreach ($byComp as $comp) {
            $byMode[$comp['mode']][] = $comp;
        }

        return $byMode;
    }

    // ---------------------------------------------------------------
    // Découverte des logs
    // ---------------------------------------------------------------

    /**
     * Découvre les logs logs.tf candidats pour une liste de steamid64.
     *
     * @param  string[]  $steamid64s
     * @return array<int, array<string, mixed>>
     */
    public function discoverLogs(array $steamid64s): array
    {
        $ids = array_values(array_unique(array_filter($steamid64s, static fn (mixed $s): bool => is_string($s) && $s !== '')));
        if ($ids === []) {
            return [];
        }

        $data = $this->fetchLogs('https://logs.tf/api/v1/log?player='.implode(',', $ids), self::CACHE_TTL_S);
        $logs = is_array($data) ? ($data['logs'] ?? []) : [];

        $out = [];
        foreach ($logs as $log) {
            $logId = (int) ($log['id'] ?? 0);
            if ($logId <= 0) {
                continue;
            }

            $title = (string) ($log['title'] ?? '');
            $out[] = [
                'id' => $logId,
                'title' => $title,
                'map' => (string) ($log['map'] ?? ''),
                'date' => (int) ($log['date'] ?? 0),
                'players' => (int) ($log['players'] ?? 0),
                'mode' => self::gameModeFromLogTitle($title),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $out;
    }

    // ---------------------------------------------------------------
    // Agrégation des stats du roster sur un lot de logs
    // ---------------------------------------------------------------

    /**
     * Agrège les stats de tous les membres du roster présents dans chaque log
     * (un seul fetch par log, réutilisé pour tout le roster).
     *
     * @param  array<int, array<string, mixed>>  $players  roster [{steamid64, name, role}]
     * @param  int[]  $logIds
     * @param  \Closure(int): (array|null)|null  $logFetcher  Récupère le détail d'un log logs.tf
     * @return array{players: array<int, array<string, mixed>>, logs: array<int, array<string, mixed>>}
     */
    public function aggregateRoster(array $players, array $logIds, ?\Closure $logFetcher = null, ?callable $progress = null): array
    {
        $roster = [];
        foreach ($players as $member) {
            $steamid64 = (string) ($member['steamid64'] ?? '');
            if ($steamid64 === '') {
                continue;
            }
            $steamid3 = SteamId::toSteamId3($steamid64);
            $roster[$steamid3] = [
                'steamid' => $steamid3,
                'steamid64' => $steamid64,
                'name' => (string) ($member['name'] ?? ''),
                'role' => (string) ($member['role'] ?? ''),
            ];
        }

        $logIds = array_values(array_unique(array_filter(array_map('intval', $logIds), static fn (int $id): bool => $id > 0)));
        $fetcher = $logFetcher ?? static fn (int $logId): ?array => JsonClient::get('https://logs.tf/api/v1/log/'.$logId);
        $total = count($logIds);

        $acc = [];
        foreach ($roster as $steamid3 => $info) {
            $acc[$steamid3] = [
                'steamid' => $info['steamid'],
                'steamid64' => $info['steamid64'],
                'name' => $info['name'],
                'role' => $info['role'],
                'matches' => 0,
                'kills' => 0,
                'deaths' => 0,
                'dmg' => 0,
                'heal' => 0,
                'dpmSum' => 0,
                'dpmCount' => 0,
            ];
        }

        $logs = [];
        foreach ($logIds as $i => $logId) {
            $num = $i + 1;

            if ($progress !== null) {
                $progress($i, $total, 'Récupération du log '.$num.'/'.$total.' (ID: '.$logId.')…', ['log_id' => $logId, 'log_status' => 'fetching']);
            }

            $entry = ['log_id' => $logId, 'found' => false, 'present' => 0, 'error' => null];

            try {
                $details = $fetcher($logId);
            } catch (\Throwable $e) {
                $details = null;
                $entry['error'] = $e->getMessage();
            }

            if (is_array($details) && isset($details['players'])) {
                $entry['found'] = true;
                $parsed = LogParser::extract($details);

                foreach ($parsed as $steamid3 => $row) {
                    if (! isset($acc[$steamid3])) {
                        continue;
                    }

                    $acc[$steamid3]['matches']++;
                    $acc[$steamid3]['kills'] += (int) $row['kills'];
                    $acc[$steamid3]['deaths'] += (int) $row['deaths'];
                    $acc[$steamid3]['dmg'] += (int) $row['dmg'];
                    $acc[$steamid3]['heal'] += (int) $row['heal'];

                    if ((int) $row['length'] > 0) {
                        $acc[$steamid3]['dpmSum'] += (int) $row['dapm'];
                        $acc[$steamid3]['dpmCount']++;
                    }

                    $entry['present']++;
                }
            } elseif (is_array($details)) {
                $entry['error'] = 'Log introuvable sur logs.tf (ID '.$logId.').';
            } else {
                $entry['error'] = 'Impossible de récupérer le log '.$logId.' (API injoignable).';
            }

            $logs[] = $entry;

            if ($progress !== null) {
                $status = $entry['present'] > 0 ? 'found' : ($entry['found'] ? 'absent' : 'error');
                $detail = $status === 'found' ? 'Joueurs trouvés' : ($status === 'absent' ? 'Aucun joueur du roster' : 'Erreur');
                $progress($i + 1, $total, 'Log '.$num.'/'.$total.' (ID: '.$logId.') — '.$detail, ['log_id' => $logId, 'log_status' => $status]);
            }
        }

        $playersOut = [];
        foreach ($acc as $info) {
            $kd = $info['deaths'] > 0
                ? round($info['kills'] / $info['deaths'], 2)
                : ($info['kills'] > 0 ? (float) $info['kills'] : 0.0);
            $dpm = $info['dpmCount'] > 0 ? (int) round($info['dpmSum'] / $info['dpmCount'], 0) : null;

            $playersOut[] = [
                'steamid' => $info['steamid'],
                'steamid64' => $info['steamid64'],
                'name' => $info['name'],
                'role' => $info['role'],
                'matches' => $info['matches'],
                'kills' => $info['kills'],
                'deaths' => $info['deaths'],
                'dmg' => $info['dmg'],
                'heal' => $info['heal'],
                'kd' => $kd,
                'dpm' => $dpm,
            ];
        }

        usort($playersOut, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['players' => $playersOut, 'logs' => $logs];
    }

    // ---------------------------------------------------------------
    // HTTP + cache
    // ---------------------------------------------------------------

    private function fetchEtf2l(string $url, int $ttl): ?array
    {
        if ($this->etf2lFetcher !== null) {
            return ($this->etf2lFetcher)($url);
        }

        return $this->cachedJson($url, $ttl);
    }

    private function fetchLogs(string $url, int $ttl): ?array
    {
        if ($this->logsFetcher !== null) {
            return ($this->logsFetcher)($url);
        }

        return $this->cachedJson($url, $ttl);
    }

    private function cachedJson(string $url, int $ttl): ?array
    {
        $cacheFile = hlfr_data_path('tool_stats_'.md5($url).'.json');
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $this->throttle();

        $meta = JsonClient::getWithMeta($url, 15, 'Highlander France/1.0', ['Accept: application/json']);
        if ($meta['curl_error'] !== '') {
            return null;
        }

        $data = $meta['data'];
        if (! is_array($data)) {
            return null;
        }

        @file_put_contents($cacheFile, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);

        return $data;
    }

    private function throttle(): void
    {
        $elapsed = microtime(true) - $this->lastHttpAt;
        if ($this->lastHttpAt > 0 && $elapsed < self::HTTP_DELAY_S) {
            usleep((int) ((self::HTTP_DELAY_S - $elapsed) * 1e6));
        }
        $this->lastHttpAt = microtime(true);
    }
}
