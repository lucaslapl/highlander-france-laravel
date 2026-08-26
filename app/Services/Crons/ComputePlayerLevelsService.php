<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;
use App\Services\JsonClient;
use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

/**
 * Calcul du niveau "réel" des joueurs inscrits (app:compute-player-levels).
 *
 * Principe : un joueur affiche la division de ses derniers matchs, mais les
 * remplacements ("mercs") dans d'autres équipes faussent cette image. On
 * calcule donc, par mode de jeu (9v9 / 6s), la moyenne des tiers des
 * 3 dernières compétitions jouées avec son équipe officielle :
 *  - forfaits (defaultwin) et matchs sans division exclus ;
 *  - l'équipe jouée par match est déduite du champ was_in_team renvoyé par
 *    l'API, et seuls les matchs de l'équipe majoritaire de la compétition
 *    sont comptés (élimination naturelle des matchs de remplacement).
 */
final class ComputePlayerLevelsService
{
    private const SCRIPT_NAME = 'compute_player_levels.php';

    /** Verrou anti-concurrence : une seule exécution à la fois (cron + panel admin). */
    private const LOCK_FILE = 'compute_player_levels.lock';

    /** Délai minimal entre deux appels HTTP réels (rate-limit ETF2L : 60 req/min). */
    private const API_CALL_DELAY_S = 1.1;

    /** Timeout cURL par appel (connexion plafonnée à 5 s côté JsonClient). */
    private const HTTP_TIMEOUT_S = 15;

    /** Durée de vie (s) du cache des historiques de résultats joueurs. */
    private const CACHE_TTL_RESULTS = 7 * 86400;

    private const RESULTS_PER_PAGE = 50;

    /** Nombre de compétitions (les plus récentes) prises en compte par mode. */
    private const MAX_COMPETITIONS_PER_MODE = 3;

    /** Type de compétition API => game_mode utilisé sur le site. */
    private const MODE_MAP = [
        'Highlander' => '9v9',
        '6v6' => '6s',
    ];

    private \PDO $db;

    /** Timestamp (microtime) du dernier appel HTTP réel, pour le rate-limit. */
    private float $lastHttpAt = 0;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
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
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Rate-limit uniquement sur les vrais appels HTTP (un cache hit ne compte pas).
        $elapsed = microtime(true) - $this->lastHttpAt;
        if ($this->lastHttpAt > 0 && $elapsed < self::API_CALL_DELAY_S) {
            usleep((int) ((self::API_CALL_DELAY_S - $elapsed) * 1e6));
        }
        $this->lastHttpAt = microtime(true);

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
                $lastError = 'erreur cURL : ' . $meta['curl_error'];
                continue;
            }

            if (!is_array($meta['data'])) {
                // Réponse non décodable en JSON : le plus souvent une page HTML
                // d'erreur (Cloudflare / 429) renvoyée à la place du payload.
                $lastError = 'HTTP ' . $meta['http_code'] . ' avec réponse non-JSON';
                continue;
            }

            return $meta['data'];
        }

        throw new \RuntimeException("Appel API ETF2L impossible après {$attempts} tentatives ({$url}) : " . $lastError);
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
            $url = 'https://api-v2.etf2l.org/player/' . $steamid64 . '/results?limit=' . self::RESULTS_PER_PAGE . '&page=' . $page;
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
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

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
            ->query("SELECT steamid FROM players_info WHERE created_at IS NOT NULL ORDER BY steamid")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $computed = 0;
        $failed = 0;

        foreach ($registered as $steamid3) {
            try {
                $computed += $this->computePlayer((string) $steamid3);
            } catch (\Throwable $e) {
                $failed++;
                error_log('Calcul niveau joueur ' . $steamid3 . ' : ' . $e->getMessage());
            }
        }

        $statusMsg = 'SUCCESS (' . count($registered) . ' joueur(s) traité(s), '
            . $computed . ' niveau(x) calculé(s)'
            . ($failed > 0 ? ', ' . $failed . ' en échec' : '') . ')';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        return 'Niveaux calculés pour ' . $computed . ' mode(s) de jeu sur ' . count($registered) . ' joueur(s) inscrit(s)'
            . ($failed > 0 ? ' — attention : ' . $failed . ' joueur(s) en échec (voir log)' : '') . '.';
    }

    /**
     * Calcule et stocke les niveaux d'un joueur (un par mode de jeu).
     *
     * @return int Nombre de modes de jeu avec un niveau calculé.
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
            'REPLACE INTO player_levels (steamid, game_mode, tier_moyen, division_label, nb_matchs_comptes, nb_competitions, computed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
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
     * Coeur du calcul : transforme une liste de résultats bruts API en niveaux
     * par mode de jeu.
     *
     * @param  array<int, array<string, mixed>> $results
     * @return array<int, array{game_mode: string, tier_moyen: float|null, division_label: string|null, nb_matchs_comptes: int, nb_competitions: int}>
     */
    public function levelsFromResults(array $results): array
    {
        // 1. Normalisation : on ne garde que les matchs exploitables (mode connu,
        // division tierée, pas de forfait, équipe identifiable via was_in_team).
        $byCompetition = [];

        foreach ($results as $r) {
            $type = (string) ($r['competition']['type'] ?? '');
            if (!isset(self::MODE_MAP[$type])) {
                continue;
            }

            if (!empty($r['defaultwin'])) {
                continue;
            }

            $tier = $r['division']['tier'] ?? null;
            $divisionName = $r['division']['name'] ?? null;
            if (!is_int($tier) || !is_string($divisionName) || $divisionName === '') {
                continue;
            }

            $teamId = $this->playedForTeamId($r);
            if ($teamId === null) {
                continue;
            }

            $compId = (int) ($r['competition']['id'] ?? 0);
            if ($compId === 0) {
                continue;
            }

            $comp = &$byCompetition[$compId];
            if (!isset($comp)) {
                $comp = [
                    'game_mode' => self::MODE_MAP[$type],
                    'time' => 0,
                    'team_counts' => [],
                    'matches' => [],
                ];
            }

            $comp['time'] = max($comp['time'], (int) ($r['time'] ?? 0));
            $comp['team_counts'][$teamId] = ($comp['team_counts'][$teamId] ?? 0) + 1;
            $comp['matches'][] = ['team_id' => $teamId, 'tier' => $tier, 'division' => $divisionName];
        }

        // Brise la référence héritée de la boucle (pitfall foreach/référence).
        unset($comp);

        // 2. Filtre anti-merc : dans chaque compétition, seule l'équipe avec
        // laquelle le joueur a disputé le plus de matchs est la sienne.
        $byMode = [];

        foreach ($byCompetition as $comp) {
            arsort($comp['team_counts']);
            $ownTeamId = (int) array_key_first($comp['team_counts']);
            $ownMatches = array_values(array_filter(
                $comp['matches'],
                static fn (array $m): bool => $m['team_id'] === $ownTeamId
            ));

            if ($ownMatches !== []) {
                $byMode[$comp['game_mode']][] = ['time' => $comp['time'], 'matches' => $ownMatches];
            }
        }

        // 3. Par mode : les MAX_COMPETITIONS_PER_MODE compétitions les plus récentes.
        $levels = [];

        foreach ($byMode as $gameMode => $competitions) {
            usort($competitions, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
            $kept = array_slice($competitions, 0, self::MAX_COMPETITIONS_PER_MODE);
            $matches = array_merge(...array_map(static fn (array $c): array => $c['matches'], $kept));

            if ($matches === []) {
                continue;
            }

            $tiers = array_map(static fn (array $m): int => $m['tier'], $matches);
            $average = array_sum($tiers) / count($tiers);

            $levels[] = [
                'game_mode' => $gameMode,
                'tier_moyen' => round($average, 2),
                'division_label' => $this->labelForTier($matches, $average),
                'nb_matchs_comptes' => count($matches),
                'nb_competitions' => count($kept),
            ];
        }

        usort($levels, static fn (array $a, array $b): int => strcmp($a['game_mode'], $b['game_mode']));

        return $levels;
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

    /**
     * Libellé de division correspondant au tier moyen : nom de division le plus
     * fréquent chez le joueur pour le tier arrondi, sinon le tier disponible
     * le plus proche.
     *
     * @param  array<int, array{team_id: int, tier: int, division: string}> $matches
     */
    private function labelForTier(array $matches, float $averageTier): ?string
    {
        $namesByTier = [];
        foreach ($matches as $m) {
            $namesByTier[$m['tier']][$m['division']] = ($namesByTier[$m['tier']][$m['division']] ?? 0) + 1;
        }

        $targetTier = (int) round($averageTier);

        while ($targetTier >= 0) {
            $candidate = $namesByTier[$targetTier] ?? null;
            if ($candidate !== null) {
                arsort($candidate);

                return (string) array_key_first($candidate);
            }
            $targetTier--;
        }

        // Aucun tier inférieur ou égal : on prend le plus bas disponible.
        ksort($namesByTier);
        $lowest = array_key_first($namesByTier);
        arsort($namesByTier[$lowest]);

        return (string) array_key_first($namesByTier[$lowest]);
    }
}
