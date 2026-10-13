<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;
use App\Services\Etf2lRateLimiter;
use App\Services\JsonClient;
use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

/**
 * Calcul de l'expérience ETF2L des joueurs inscrits (app:compute-player-levels).
 *
 * Implémente la règle 2.7 "Player Experience" des règles ETF2L
 * (https://rules.etf2l.org/docs/general-2/#27-player-experience) :
 *  - seules les saisons officielles ("Highlander Season" / "6v6 Season")
 *    comptent ; coupes, nations cups et qualifications (pre-season) exclues ;
 *  - une saison n'est "active" que si le joueur y a disputé au moins
 *    MIN_MATCHES_ACTIVE_SEASON matchs avec son équipe (filtre anti-merc) ;
 *  - l'expérience d'un mode est la division la plus haute jouée dans les
 *    MAX_ACTIVE_SEASONS dernières saisons actives, tous modes confondus ;
 *  - les matchs de phase finale comptent pour le tier au-dessus ("Mid
 *    Playoffs", "Open 3rd Place", rounds "Semi-Finals"/"Grand Final"...) ;
 *  - l'expérience décroît d'une division par tranche de deux années pleines
 *    écoulées depuis le dernier match de la saison (plancher : Low/D5) ;
 *  - l'expérience d'un mode est convertie dans l'autre mode via les tables
 *    officielles (le meilleur des deux est retenu) ;
 *  - pour les tiers les plus bas (Open/Fresh/Division 6), les saisons actives
 *    de toute l'histoire du joueur comptent, sans fenêtre ni decay.
 *
 * Le decay dépend de la date du jour : il est calculé par le cron et figé en
 * base jusqu'au prochain passage (décision mainteneur).
 *
 * Limites connues : la division de l'équipe actuelle (roster) et l'expérience
 * dans d'autres ligues (RGL, ozfortress...) ne sont pas prises en compte.
 */
final class ComputePlayerLevelsService
{
    private const SCRIPT_NAME = 'compute_player_levels.php';

    /** Verrou anti-concurrence : une seule exécution à la fois (cron + panel admin). */
    private const LOCK_FILE = 'compute_player_levels.lock';

    /** Timeout cURL par appel (connexion plafonnée à 5 s côté JsonClient). */
    private const HTTP_TIMEOUT_S = 15;

    /** Durée de vie (s) du cache des historiques de résultats joueurs :
     * 6 jours, juste sous la cadence hebdomadaire — le run suivant (dimanche
     * pour les niveaux, mardi pour le palmarès) trouve un cache encore chaud
     * et ne re-télécharge pas tout à chaque fois. */
    private const CACHE_TTL_RESULTS = 6 * 86400;

    private const RESULTS_PER_PAGE = 50;

    /**
     * Fenêtre d'expérience : les N dernières saisons actives du joueur,
     * tous modes de jeu confondus (règle 2.7).
     */
    private const MAX_ACTIVE_SEASONS = 3;

    /** Saison "active" : nombre minimal de matchs joués (règle 2.7). */
    private const MIN_MATCHES_ACTIVE_SEASON = 3;

    /** Decay : une division de moins par tranche de deux années pleines. */
    private const DECAY_STEP_SECONDS = 2 * 365 * 86400;

    /** Plancher de decay sur l'échelle des règles (5 = Division 5 / Low). */
    private const DECAY_FLOOR = 5;

    /** Seules les saisons officielles sont prises en compte (pas les coupes). */
    private const SEASON_CATEGORIES = [
        'Highlander Season',
        '6v6 Season',
    ];

    /** Type de compétition API => game_mode utilisé sur le site. */
    private const MODE_MAP = [
        'Highlander' => '9v9',
        '6v6' => '6s',
    ];

    /**
     * Échelles d'affichage par mode (rang 0 = plus haut niveau). Ce sont ces
     * libellés qui sont stockés dans player_levels.division_label. "Top
     * Division" est le nom historique du plus haut palier 6v6.
     */
    private const LADDERS = [
        '9v9' => ['Premiership', 'High', 'Mid', 'Low', 'Open'],
        '6s' => ['Top Division', 'Division 1', 'Division 2', 'Division 3', 'Division 4', 'Low', 'Open', 'Fresh'],
    ];

    /**
     * Rang d'affichage => échelle commune des règles ETF2L (0 = Premiership,
     * 5 = Division 5, 6 = Division 6). High couvre Division 1/2 et Mid couvre
     * Division 3/4 (note de la règle 2.7) : on retient la borne conservatrice
     * (D2/D4), lecture confirmée par l'exemple officiel "HL Open, 6s Mid,
     * 6s Mid -> HL Low" (6s Mid converti en D5, pas en D4).
     */
    private const RANK_TO_RULES = [
        '9v9' => [0, 2, 4, 5, 6],
        '6s' => [0, 1, 2, 3, 4, 5, 6, 6],
    ];

    /** Échelle des règles => rang d'affichage (inverse de RANK_TO_RULES). */
    private const RULES_TO_RANK = [
        '9v9' => [0, 1, 1, 2, 2, 3, 4],
        '6s' => [0, 1, 2, 3, 4, 5, 6, 6],
    ];

    /**
     * Tables de conversion croisée de la règle 2.7 (index = division
     * d'origine sur l'échelle des règles, valeur = division convertie) :
     *  - 9v9 -> 6v6 : Premiership -> D2, D1 -> D3, ..., D4 et moins -> D6 ;
     *  - 6v6 -> 9v9 : Premiership -> D1, D1 -> D2, ..., D5/D6 -> D5/D6.
     */
    private const CONVERSION = [
        '9v9' => [2, 3, 4, 5, 6, 6, 6],
        '6s' => [1, 2, 3, 4, 5, 5, 6],
    ];

    /**
     * Rangs d'affichage des "tiers les plus bas" (règle 2.7 : Open, Fresh,
     * Division 6) : les saisons actives de toute l'histoire y comptent, sans
     * fenêtre ni decay.
     */
    private const LOWEST_TIERS = [
        '9v9' => [4],
        '6s' => [6, 7],
    ];

    /**
     * Suffixes de compétitions exclues de l'expérience : qualifications
     * (pre-season, exclues par la règle 2.7). Le nom de saison est la partie
     * avant le dernier ":" du nom de compétition.
     */
    private const EXCLUDED_SUFFIX = '/qualifier/i';

    /**
     * Suffixes de phase finale ("Mid Playoffs", "Open 3rd Place") : compétition
     * séparée de la saison régulière, fusionnée par clé de saison, matchs
     * comptés pour le tier au-dessus (règle 2.7).
     */
    private const PLAYOFF_SUFFIX = '/playoffs?\b|3rd place/i';

    /**
     * Rounds de phase finale pour les anciennes saisons où les playoffs
     * étaient dans la même compétition que la saison régulière.
     */
    private const PLAYOFF_ROUND = '/^(playoffs?\b|playoffs?:|quarter ?finals?|semi ?finals?|grand final|finals?\b|round of|bracket|3rd place)/i';

    /**
     * Normalisation des noms de division API vers un rang d'affichage, par
     * mode (source primaire : le champ "tier" de l'API est incohérent selon
     * les époques — ex. 6s "Open" et "Fresh" tous deux tier 5 aujourd'hui).
     * Première règle qui matche gagne ; un nom sans règle tombe sur le repli
     * tier API, sinon le match est exclu du calcul. Équivalences règle 2.7 :
     * High = Division 1/2, Mid = Division 3/4, Low = Division 5,
     * Open/Fresh/Division 6 = tiers les plus bas.
     *
     * @var array<string, array<string, int>>
     */
    private const DIVISION_RULES = [
        '9v9' => [
            '/prem/i' => 0,
            '/\bhigh\b|division\s*[12]|div\.?\s*[12]/i' => 1,
            '/\bmid\b|division\s*[34]|div\.?\s*[34]/i' => 2,
            '/\blow\b|division\s*5|div\.?\s*5/i' => 3,
            '/\bopen\b|division\s*6|div\.?\s*6/i' => 4,
        ],
        '6s' => [
            '/\bprem|top division/i' => 0,
            '/division\s*1|div\.?\s*1/i' => 1,
            '/\bhigh\b|division\s*2|div\.?\s*2/i' => 2,
            '/division\s*3|div\.?\s*3/i' => 3,
            '/\bmid\b|division\s*4|div\.?\s*4/i' => 4,
            '/\blow\b|division\s*5|div\.?\s*5/i' => 5,
            '/\bopen\b|division\s*6|div\.?\s*6/i' => 6,
            '/\bfresh\b/i' => 7,
        ],
    ];

    /**
     * Repli sur le champ "tier" API quand le nom de division ne matche aucune
     * règle (ex. "Round Robin Group E", tier 0). Pour le 9v9, les valeurs 5/6
     * correspondent aux vieilles saisons numérotées (D5 => Low, D6 => Open).
     */
    private const TIER_RANKS = [
        '9v9' => [0, 1, 2, 3, 4, 3, 4],
        '6s' => [0, 1, 2, 3, 4, 5, 6, 7],
    ];

    private \PDO $db;

    /** Espacement des appels HTTP réels, partagé entre tous les processus. */
    private Etf2lRateLimiter $limiter;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
        $this->limiter = new Etf2lRateLimiter;
    }

    /**
     * Appel API avec cache persistant en base (table etf2l_api_cache) : si la
     * réponse est encore fraîche, aucune requête HTTP n'est émise.
     */
    private function cachedGet(string $url): array
    {
        $cacheStmt = $this->db->prepare('SELECT payload FROM etf2l_api_cache WHERE url = ? AND fetched_at > ?');
        $cacheStmt->execute([$url, time() - self::CACHE_TTL_RESULTS]);
        $payload = $cacheStmt->fetchColumn();

        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && $this->isValidApiPayload($decoded)) {
                return $decoded;
            }
        }

        // Rate-limit uniquement sur les vrais appels HTTP (un cache hit ne compte pas).
        $this->limiter->wait();

        $data = $this->fetchWithRetry($url);

        // Upsert portable : syntaxe MySQL (prod MariaDB) ou SQLite (tests).
        $isMysql = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT INTO etf2l_api_cache (url, payload, fetched_at) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)'
            : 'INSERT INTO etf2l_api_cache (url, payload, fetched_at) VALUES (?, ?, ?)
               ON CONFLICT(url) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at';

        $this->db->prepare($sql)->execute([$url, json_encode($data, JSON_THROW_ON_ERROR), time()]);

        return $data;
    }

    /**
     * Un payload API servi depuis le cache n'est exploitable que s'il contient
     * un bloc status/data/tables. Une réponse de throttling (ex. le message
     * "Too Many Attempts." sans clé status) ne doit jamais être servie : on la
     * rejette pour forcer un re-fetch HTTP (le retry traite alors le 429).
     */
    private function isValidApiPayload(array $payload): bool
    {
        if ($payload === []) {
            return true;
        }

        return isset($payload['status']) || isset($payload['data']) || isset($payload['tables']);
    }

    /**
     * Appel API avec nouvelles tentatives sur réponse transitoire (payload
     * throttlé, erreur cURL, réponse non-JSON type page HTML Cloudflare).
     */
    private function fetchWithRetry(string $url, int $attempts = 3): array
    {
        // Attente avant chaque nouvelle tentative (backoff progressif).
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
                // Réponse non décodable en JSON : le plus souvent une page HTML
                // d'erreur (Cloudflare / 429) renvoyée à la place du payload.
                $lastError = 'HTTP '.$meta['http_code'].' avec réponse non-JSON';

                continue;
            }

            $code = isset($meta['data']['status']['code']) ? (int) $meta['data']['status']['code'] : null;

            if ($code === 200) {
                return $meta['data'];
            }

            // Réponse sans bloc status (ex. "Too Many Attempts." du throttling
            // Laravel) : on s'appuie sur le code HTTP réel pour décider si le
            // corps est exploitable, sinon c'est une erreur transitoire et on
            // retente (jamais de mise en cache d'une réponse throttlée).
            if ($code === null) {
                $httpCode = (int) ($meta['http_code'] ?? 0);

                if ($httpCode >= 200 && $httpCode < 300) {
                    return $meta['data'];
                }

                $lastError = 'HTTP '.$httpCode.' (réponse sans status)';

                continue;
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

    /**
     * Historique complet des résultats d'un joueur (toutes pages).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchResults(string $steamid64): array
    {
        $results = [];

        for ($page = 1; ; $page++) {
            $url = 'https://api-v2.etf2l.org/player/'.$steamid64.'/results?limit='.self::RESULTS_PER_PAGE.'&page='.$page;
            $responseObj = $this->cachedGet($url);

            $pageResults = $responseObj['data'] ?? [];
            if ($pageResults !== []) {
                $results[] = $pageResults;
            }

            $lastPage = (int) ($responseObj['last_page'] ?? $page);
            if ($page >= $lastPage) {
                break;
            }
        }

        return $results === [] ? [] : array_merge(...$results);
    }

    public function run(): string
    {
        // Une seule exécution à la fois : un cron qui chevauche un run manuel
        // doublerait le taux d'appels et déclencherait le throttle de l'API.
        $lock = fopen(hlfr_data_path(self::LOCK_FILE), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            AdminLogger::skipped(self::SCRIPT_NAME, 'Tâche ignorée : une autre exécution est déjà en cours.');

            return 'Calcul des niveaux ignoré : une autre exécution est déjà en cours.';
        }

        try {
            return $this->doRun();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function doRun(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        // Purge des entrées de cache devenues inutiles (TTL maximal dépassé).
        $this->db->prepare('DELETE FROM etf2l_api_cache WHERE fetched_at < ?')
            ->execute([time() - self::CACHE_TTL_RESULTS]);

        // Joueurs inscrits = ceux qui se sont connectés via Steam
        // (created_at renseigné par createIfMissing / ensureCreatedAt au login).
        $registered = $this->db
            ->query('SELECT steamid FROM players_info WHERE created_at IS NOT NULL ORDER BY steamid')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $computed = 0;
        $failed = 0;
        $errors = [];

        foreach ($registered as $steamid3) {
            try {
                $computed += $this->computePlayer((string) $steamid3);
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $steamid3.' : '.$e->getMessage();
                error_log('Calcul niveau joueur '.$steamid3.' : '.$e->getMessage());
            }
        }

        $statusMsg = 'SUCCESS ('.count($registered).' joueur(s) traité(s), '
            .$computed.' niveau(x) calculé(s)'
            .($failed > 0 ? ', '.$failed.' en échec' : '').')';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        // Les premières erreurs sont remontées telles quelles dans la console
        // admin : un souci de schéma (table/colonne manquante) doit être
        // visible immédiatement sans aller fouiller les logs PHP.
        $errorReport = '';
        if ($errors !== []) {
            $shown = array_slice($errors, 0, 5);
            $errorReport = "\n\nErreurs (".$failed.") :\n- ".implode("\n- ", $shown)
                .($failed > 5 ? "\n… et ".($failed - 5).' autre(s) (voir log PHP)' : '');
        }

        return 'Niveaux calculés pour '.$computed.' mode(s) de jeu sur '.count($registered).' joueur(s) inscrit(s)'
            .($failed > 0 ? ' — attention : '.$failed.' joueur(s) en échec' : '').'.'.$errorReport;
    }

    public function computeForPlayer(string $steamid3): int
    {
        return $this->computePlayer($steamid3);
    }

    /**
     * Calcule et stocke l'expérience ETF2L d'un joueur (un par mode de jeu).
     *
     * @return int Nombre de modes de jeu avec une expérience calculée.
     */
    private function computePlayer(string $steamid3): int
    {
        $steamid64 = SteamId::toSteamId64($steamid3);
        if ($steamid64 === null) {
            return 0;
        }

        $levels = $this->levelsFromResults($this->fetchResults($steamid64));
        if ($levels === []) {
            return 0;
        }

        // REPLACE : recrée les lignes du joueur (une ancienne ligne dont le mode
        // n'a plus aucun résultat disparaît).
        $deleteStmt = $this->db->prepare('DELETE FROM player_levels WHERE steamid = ?');
        $insertStmt = $this->db->prepare(
            'REPLACE INTO player_levels (steamid, game_mode, tier_moyen, division_label, nb_matchs_comptes, nb_competitions, last_match_time, computed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $this->db->beginTransaction();

        try {
            $deleteStmt->execute([$steamid3]);

            foreach ($levels as $level) {
                $insertStmt->execute([
                    $steamid3,
                    $level['game_mode'],
                    $level['tier_moyen'],
                    $level['division_label'],
                    $level['nb_matchs_comptes'],
                    $level['nb_competitions'],
                    $level['last_match_time'],
                    time(),
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return count($levels);
    }

    /**
     * Coeur du calcul : transforme une liste de résultats bruts API en
     * expérience ETF2L par mode de jeu (règle 2.7).
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array{game_mode: string, tier_moyen: float|null, division_label: string|null, nb_matchs_comptes: int, nb_competitions: int, last_match_time: int}>
     */
    public function levelsFromResults(array $results): array
    {
        // 1. Normalisation : on ne garde que les matchs exploitables (saison
        // officielle d'un mode connu, division normalisable, pas de forfait,
        // équipe identifiable via was_in_team, pas une qualification).
        $byCompetition = [];

        foreach ($results as $r) {
            $type = (string) ($r['competition']['type'] ?? '');
            if (! isset(self::MODE_MAP[$type])) {
                continue;
            }

            if (! in_array((string) ($r['competition']['category'] ?? ''), self::SEASON_CATEGORIES, true)) {
                continue;
            }

            if (! empty($r['defaultwin'])) {
                continue;
            }

            $compId = (int) ($r['competition']['id'] ?? 0);
            if ($compId === 0) {
                continue;
            }

            $season = $this->parseCompetition((string) ($r['competition']['name'] ?? ''));
            if ($season === null) {
                continue;
            }

            $gameMode = self::MODE_MAP[$type];
            $rank = $this->matchRank($gameMode, $r)
                ?? ($season['bracket'] !== '' ? $this->canonicalRank($gameMode, $season['bracket']) : null);
            if ($rank === null) {
                continue;
            }

            $teamId = $this->playedForTeamId($r);
            if ($teamId === null) {
                continue;
            }

            // Phase finale : compétition suffixée "Playoffs"/"3rd Place", ou
            // round de phase finale dans les vieilles saisons mono-compétition.
            $isPlayoff = $season['playoff']
                || preg_match(self::PLAYOFF_ROUND, (string) ($r['round'] ?? '')) === 1;

            $comp = &$byCompetition[$compId];
            if (! isset($comp)) {
                $comp = [
                    'game_mode' => $gameMode,
                    'season_key' => $season['key'],
                    'time' => 0,
                    'team_counts' => [],
                    'matches' => [],
                ];
            }

            $comp['time'] = max($comp['time'], (int) ($r['time'] ?? 0));
            $comp['team_counts'][$teamId] = ($comp['team_counts'][$teamId] ?? 0) + 1;
            $comp['matches'][] = ['team_id' => $teamId, 'rank' => $rank, 'playoff' => $isPlayoff];
        }

        // Brise la référence héritée de la boucle (pitfall foreach/référence).
        unset($comp);

        // 2. Filtre anti-merc : dans chaque compétition, seule l'équipe avec
        // laquelle le joueur a disputé le plus de matchs est la sienne. Les
        // compétitions d'une même saison (régulière + phase finale) sont
        // fusionnées sur leur clé de saison.
        $bySeason = [];

        foreach ($byCompetition as $comp) {
            arsort($comp['team_counts']);
            $ownTeamId = (int) array_key_first($comp['team_counts']);

            foreach ($comp['matches'] as $m) {
                if ($m['team_id'] !== $ownTeamId) {
                    continue;
                }

                $season = &$bySeason[$comp['game_mode']][$comp['season_key']];
                if (! isset($season)) {
                    $season = ['time' => 0, 'matches' => []];
                }

                $season['time'] = max($season['time'], $comp['time']);
                $season['matches'][] = $m;
            }
        }

        unset($season);

        // 3. Saisons actives (>= MIN_MATCHES_ACTIVE_SEASON matchs) : le rang de
        // la saison est la division la plus haute jouée, les matchs de phase
        // finale comptant pour le tier au-dessus (règle 2.7).
        $seasons = [];

        foreach ($bySeason as $gameMode => $modeSeasons) {
            foreach ($modeSeasons as $seasonKey => $season) {
                if (count($season['matches']) < self::MIN_MATCHES_ACTIVE_SEASON) {
                    continue;
                }

                $rank = min(array_map(
                    static fn (array $m): int => $m['playoff'] ? max($m['rank'] - 1, 0) : $m['rank'],
                    $season['matches']
                ));

                $seasons[] = [
                    'game_mode' => $gameMode,
                    'key' => $seasonKey,
                    'time' => $season['time'],
                    'rank' => $rank,
                    'nb_matches' => count($season['matches']),
                ];
            }
        }

        // 4. Fenêtre : les MAX_ACTIVE_SEASONS dernières saisons actives,
        // tous modes confondus (règle 2.7). Les saisons hors fenêtre restent
        // candidates pour les tiers les plus bas.
        usort($seasons, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
        $window = array_slice($seasons, 0, self::MAX_ACTIVE_SEASONS);

        // 5. Expérience propre à chaque mode : division la plus haute de la
        // fenêtre après decay, plus les tiers les plus bas de toutes les
        // saisons actives (sans fenêtre ni decay, règle 2.7).
        $now = time();
        $ownByMode = [];
        $contribByMode = [];

        foreach ($window as $season) {
            $ownByMode[$season['game_mode']][] = $this->decayRank($season['game_mode'], $season['rank'], $season['time'], $now);
            $contribByMode[$season['game_mode']][$season['key']] = $season;
        }

        foreach ($seasons as $season) {
            if (! in_array($season['rank'], self::LOWEST_TIERS[$season['game_mode']], true)) {
                continue;
            }

            $ownByMode[$season['game_mode']][] = $season['rank'];
            $contribByMode[$season['game_mode']][$season['key']] = $season;
        }

        // 6. Conversion croisée : l'expérience d'un mode vaut aussi dans
        // l'autre via les tables officielles (le meilleur des deux gagne).
        $expByMode = [];

        foreach (self::MODE_MAP as $gameMode) {
            $other = $gameMode === '9v9' ? '6s' : '9v9';
            $own = $ownByMode[$gameMode] ?? [];
            $ownOther = $ownByMode[$other] ?? [];

            if ($own === [] && $ownOther === []) {
                continue;
            }

            $candidates = $own;
            foreach ($ownOther as $otherRank) {
                $candidates[] = $this->convertRank($other, $otherRank);
            }

            $expByMode[$gameMode] = min($candidates);
        }

        // 7. Lignes finales : une par mode avec une expérience (propre ou
        // convertie). Les compteurs décrivent les saisons qui ont contribué ;
        // un mode purement converti les laisse à zéro.
        $levels = [];

        foreach ($expByMode as $gameMode => $rank) {
            $contrib = array_values($contribByMode[$gameMode] ?? []);

            $levels[] = [
                'game_mode' => $gameMode,
                'tier_moyen' => (float) $rank,
                'division_label' => self::LADDERS[$gameMode][$rank],
                'nb_matchs_comptes' => array_sum(array_column($contrib, 'nb_matches')),
                'nb_competitions' => count($contrib),
                'last_match_time' => $contrib === [] ? 0 : max(array_column($contrib, 'time')),
            ];
        }

        usort($levels, static fn (array $a, array $b): int => strcmp($a['game_mode'], $b['game_mode']));

        return $levels;
    }

    /**
     * Clé de saison et nature d'une compétition : les phases finales sont des
     * compétitions séparées ("... : Mid Playoffs", "... : Open 3rd Place")
     * qu'il faut fusionner avec la saison régulière via la partie avant le
     * dernier ":". Retourne null pour les qualifications (pre-season exclue).
     *
     * @return array{key: string, playoff: bool, bracket: string}|null
     */
    private function parseCompetition(string $name): ?array
    {
        $pos = strrpos($name, ':');
        if ($pos === false) {
            return ['key' => $name, 'playoff' => false, 'bracket' => ''];
        }

        $key = trim(substr($name, 0, $pos));
        $suffix = trim(substr($name, $pos + 1));

        if ($key === '' || preg_match(self::EXCLUDED_SUFFIX, $suffix) === 1) {
            return null;
        }

        $playoff = preg_match(self::PLAYOFF_SUFFIX, $suffix) === 1;
        $bracket = trim((string) preg_replace(self::PLAYOFF_SUFFIX, '', $suffix));

        return ['key' => $key, 'playoff' => $playoff, 'bracket' => $bracket];
    }

    /**
     * Rang d'affichage d'un match depuis son bloc division : le nom est la
     * source primaire, le champ "tier" API sert de repli (le "bracket" des
     * compétitions de phase finale, ex. "Mid" dans "Mid Playoffs", est géré
     * par l'appelant quand la division est absente).
     *
     * @param  array<string, mixed>  $result  Résultat brut API.
     */
    private function matchRank(string $gameMode, array $result): ?int
    {
        $division = $result['division'] ?? null;
        if (! is_array($division)) {
            return null;
        }

        $name = $division['name'] ?? null;
        if (is_string($name) && $name !== '') {
            $rank = $this->canonicalRank($gameMode, $name);
            if ($rank !== null) {
                return $rank;
            }
        }

        $tier = $division['tier'] ?? null;
        if (is_int($tier) && $tier >= 0 && $tier < count(self::TIER_RANKS[$gameMode])) {
            return self::TIER_RANKS[$gameMode][$tier];
        }

        return null;
    }

    /**
     * Décroissance (règle 2.7) : une division de moins par tranche de deux
     * années pleines écoulées depuis le dernier match de la saison, avec un
     * plancher à Low/Division 5 (les tiers les plus bas ne bougent pas).
     */
    private function decayRank(string $gameMode, int $rank, int $seasonTime, int $now): int
    {
        $rules = self::RANK_TO_RULES[$gameMode][$rank];
        if ($rules >= self::DECAY_FLOOR) {
            return $rank;
        }

        $steps = intdiv(max($now - $seasonTime, 0), self::DECAY_STEP_SECONDS);
        if ($steps === 0) {
            return $rank;
        }

        return self::RULES_TO_RANK[$gameMode][min($rules + $steps, self::DECAY_FLOOR)];
    }

    /**
     * Conversion croisée (règle 2.7) : traduit le rang d'affichage d'un mode
     * dans l'échelle de l'autre mode via les tables officielles.
     */
    private function convertRank(string $sourceMode, int $rank): int
    {
        $targetMode = $sourceMode === '9v9' ? '6s' : '9v9';
        $converted = self::CONVERSION[$sourceMode][self::RANK_TO_RULES[$sourceMode][$rank]];

        return self::RULES_TO_RANK[$targetMode][$converted];
    }

    /**
     * Rang d'affichage d'une division (0 = plus haut niveau) selon les règles
     * de nommage par mode, ou null si la division n'est pas normalisable.
     */
    private function canonicalRank(string $gameMode, string $divisionName): ?int
    {
        foreach (self::DIVISION_RULES[$gameMode] ?? [] as $pattern => $rank) {
            if (preg_match($pattern, $divisionName) === 1) {
                return $rank;
            }
        }

        return null;
    }

    /**
     * Équipe pour laquelle le joueur a disputé le match, via was_in_team.
     * Retourne null si le flag est absent ou ambigu (0 ou 2 équipes marquées).
     */
    private function playedForTeamId(array $result): ?int
    {
        $marked = [];

        foreach (['clan1', 'clan2'] as $side) {
            $clan = $result[$side] ?? null;
            if (is_array($clan) && ($clan['was_in_team'] ?? false) === true) {
                $marked[] = (int) ($clan['id'] ?? 0);
            }
        }

        return count($marked) === 1 && $marked[0] > 0 ? $marked[0] : null;
    }
}
