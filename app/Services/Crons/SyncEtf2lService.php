<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;
use App\Services\JsonClient;
use Illuminate\Support\Facades\DB;

/**
 * Synchronisation de l'agenda des matchs ETF2L français (app:sync-etf2l).
 */
final class SyncEtf2lService
{
    private const SCRIPT_NAME = 'sync_etf2l.php';

    private const API_URL = 'https://api-v2.etf2l.org/matches?scheduled=1';

    /** Fenêtre (en jours) de synchro automatique des matchs terminés (cron léger).
     * L'historique complet (180 jours) passe par le backfill manuel. */
    private const HISTORY_WINDOW_DAYS = 7;

    /** Verrou anti-concurrence : une seule exécution à la fois (cron + panel admin). */
    private const LOCK_FILE = 'sync_etf2l.lock';

    /** IDs des équipes françaises sans drapeau "France". */
    private const WHITELISTED_TEAMS = [
        37618,
    ];

    /** Délai minimal entre deux appels HTTP réels (rate-limit ETF2L : 60 req/min). */
    private const API_CALL_DELAY_S = 1.1;

    /** Timeout cURL par appel (connexion plafonnée à 5 s côté JsonClient). */
    private const HTTP_TIMEOUT_S = 15;

    /** Durée de vie (s) du cache des listes de matchs (données évolutives). */
    private const CACHE_TTL_MATCHES = 600;

    /** Durée de vie (s) du cache des fiches équipes/rosters (peu volatiles). */
    private const CACHE_TTL_TEAMS = 7 * 86400;

    /** Nombre maximal d'appels /matches/{id} par exécution (backfill progressif). */
    private const ENRICH_MAX_PER_RUN = 45;

    private \PDO $db;

    /** Timestamp (microtime) du dernier appel HTTP réel, pour le rate-limit. */
    private float $lastHttpAt = 0;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    /**
     * Appel API avec cache persistant en base : si la réponse est encore fraîche,
     * aucune requête HTTP n'est émise (exécutions rapprochées quasi instantanées,
     * et rosters re-téléchargés au maximum une fois par semaine).
     */
    private function cachedGet(string $url, int $ttl): array
    {
        $cacheStmt = $this->db->prepare('SELECT payload FROM etf2l_api_cache WHERE url = ? AND fetched_at > ?');
        $cacheStmt->execute([$url, time() - $ttl]);
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

        $responseObj = $this->fetchWithRetry($url);

        $this->db
            ->prepare('INSERT INTO etf2l_api_cache (url, payload, fetched_at) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)')
            ->execute([$url, json_encode($responseObj, JSON_THROW_ON_ERROR), time()]);

        return $responseObj;
    }

    private function fetchAllPages(string $baseUrl): array
    {
        $matches = [];

        for ($page = 1; ; $page++) {
            $responseObj = $this->cachedGet($baseUrl . '&page=' . $page, self::CACHE_TTL_MATCHES);

            $results = $responseObj['results'] ?? [];
            $pageMatches = $results['data'] ?? [];

            if ($pageMatches !== []) {
                $matches[] = $pageMatches;
            }

            $lastPage = (int) ($results['last_page'] ?? $page);
            if ($page >= $lastPage) {
                break;
            }
        }

        return $matches === [] ? [] : array_merge(...$matches);
    }

    /**
     * Appel API avec nouvelles tentatives sur réponse transitoire
     * (payload throttlé, 429, erreur cURL, 5xx ou réponse non-JSON type page
     * HTML Cloudflare). Respecte l'en-tête Retry-After renvoyé par l'API.
     */
    private function fetchWithRetry(string $url, int $attempts = 3): array
    {
        // Attente avant chaque nouvelle tentative (backoff progressif).
        $backoffs = [0, 5, 20];
        $lastHeaders = [];
        $lastError = 'raison inconnue';

        for ($i = 1; $i <= $attempts; $i++) {
            if ($i > 1) {
                $retryAfter = isset($lastHeaders['retry-after']) ? (int) $lastHeaders['retry-after'] : 0;
                sleep(min(max($retryAfter, $backoffs[min($i - 1, count($backoffs) - 1)]), 90));
            }

            $meta = JsonClient::getWithMeta($url, self::HTTP_TIMEOUT_S, 'Highlander France Bot/1.0', ['Accept: application/json']);
            $lastHeaders = $meta['headers'];

            if ($meta['curl_error'] !== '') {
                $lastError = 'erreur cURL : ' . $meta['curl_error'];
                continue;
            }

            if ($meta['data'] === null) {
                // Réponse non décodable en JSON : le plus souvent une page HTML
                // d'erreur (Cloudflare / 429) renvoyée à la place du payload.
                $lastError = 'HTTP ' . $meta['http_code'] . ' avec réponse non-JSON';
                continue;
            }

            $code = isset($meta['data']['status']['code']) ? (int) $meta['data']['status']['code'] : null;

            if ($code === 200) {
                return $meta['data'];
            }

            // Pas de clé status (= throttle Laravel) ou code transitoire : on retente.
            if ($code === null || in_array($code, [429, 500, 502, 503, 504], true)) {
                $lastError = 'HTTP ' . ($code ?? $meta['http_code']) . ' (réponse transitoire)';
                continue;
            }

            $msg = (string) ($meta['data']['status']['message'] ?? 'Réponse invalide/inaccessible');
            throw new \RuntimeException("L'API ETF2L a répondu négativement pour {$url} : " . $msg);
        }

        throw new \RuntimeException("Appel API ETF2L impossible après {$attempts} tentatives ({$url}) : " . $lastError);
    }

    public function run(int $historyDays = self::HISTORY_WINDOW_DAYS): string
    {
        // Une seule exécution à la fois : un cron qui chevauche un run manuel
        // doublerait le taux d'appels et déclencherait le throttle de l'API.
        $lock = fopen(hlfr_data_path(self::LOCK_FILE), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return 'Synchronisation ETF2L ignorée : une autre exécution est déjà en cours.';
        }

        try {
            return $this->doRun(max(1, $historyDays));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function doRun(int $historyDays): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        // Purge des entrées de cache devenues inutiles (TTL maximal dépassé).
        $this->db->prepare('DELETE FROM etf2l_api_cache WHERE fetched_at < ?')
            ->execute([time() - self::CACHE_TTL_TEAMS]);

        // Historique durable : on cumule matchs à venir + matchs passés récents,
        // et on upsert au lieu de tout effacer (les URLs /match/{id} restent valides).
        // Le `from` est arrondi au jour : l'URL reste stable sur 24 h, sinon le
        // cache ne serait jamais utilisé pour les pages d'historique.
        $from = time() - $historyDays * 86400;
        $from -= $from % 86400;
        $matches = array_merge(
            $this->fetchAllPages(self::API_URL),
            $this->fetchAllPages('https://api-v2.etf2l.org/matches?scheduled=0&from=' . $from)
        );

        $upsertedCount = 0;
        // NB : syntaxe VALUES(col) requise pour MariaDB (l'alias de ligne
        // "AS new_row" n'existe que sur MySQL >= 8.0.19).
        $stmt = $this->db->prepare('
            INSERT INTO etf2l_matches (match_id, team1_name, team2_name, match_date, competition_name, team1_country, team2_country, team1_id, team2_id, maps, r1, r2, map_results)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                team1_name = VALUES(team1_name),
                team2_name = VALUES(team2_name),
                match_date = VALUES(match_date),
                competition_name = VALUES(competition_name),
                team1_country = VALUES(team1_country),
                team2_country = VALUES(team2_country),
                team1_id = VALUES(team1_id),
                team2_id = VALUES(team2_id),
                maps = COALESCE(VALUES(maps), maps),
                r1 = COALESCE(VALUES(r1), r1),
                r2 = COALESCE(VALUES(r2), r2),
                map_results = COALESCE(VALUES(map_results), map_results)
        ');

        $matchTeamIds = [];
        $frenchMatches = []; // match_id => ['time' => int, 'submitted' => ?int]

        // Une seule transaction pour tous les upserts.
        $this->db->beginTransaction();

        try {
            foreach ($matches as $m) {
                $t1 = $m['clan1'] ?? null;
                $t2 = $m['clan2'] ?? null;

                if (!$t1 || !$t2) {
                    continue;
                }

                $t1Id = (int) ($t1['id'] ?? 0);
                $t2Id = (int) ($t2['id'] ?? 0);

                $isFr1 = (isset($t1['country']) && strtolower((string) $t1['country']) === 'france');
                $isFr2 = (isset($t2['country']) && strtolower((string) $t2['country']) === 'france');
                $isWhitelisted1 = in_array($t1Id, self::WHITELISTED_TEAMS, true);
                $isWhitelisted2 = in_array($t2Id, self::WHITELISTED_TEAMS, true);

                if (!$isFr1 && !$isFr2 && !$isWhitelisted1 && !$isWhitelisted2) {
                    continue;
                }

                $stmt->execute([
                    $m['id'] ?? null,
                    $t1['name'] ?? 'TBD',
                    $t2['name'] ?? 'TBD',
                    (int) ($m['time'] ?? time()),
                    $m['competition']['name'] ?? 'Compétition ETF2L',
                    isset($t1['country']) ? strtolower((string) $t1['country']) : 'unknown',
                    isset($t2['country']) ? strtolower((string) $t2['country']) : 'unknown',
                    $t1Id,
                    $t2Id,
                    isset($m['maps']) ? json_encode(array_values((array) $m['maps']), JSON_THROW_ON_ERROR) : null,
                    isset($m['r1']) ? (int) $m['r1'] : null,
                    isset($m['r2']) ? (int) $m['r2'] : null,
                    isset($m['map_results']) ? json_encode($m['map_results'], JSON_THROW_ON_ERROR) : null,
                ]);

                if (isset($m['id'])) {
                    $frenchMatches[(int) $m['id']] = [
                        'time' => (int) ($m['time'] ?? 0),
                        'submitted' => isset($m['submitted']) ? (int) $m['submitted'] : null,
                    ];
                }

                if ($t1Id > 0) {
                    $matchTeamIds[$t1Id] = true;
                }
                if ($t2Id > 0) {
                    $matchTeamIds[$t2Id] = true;
                }

                $upsertedCount++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $enriched = 0;
        $toEnrich = $this->matchesNeedingEnrichment($frenchMatches);
        if ($toEnrich !== []) {
            $enriched = $this->enrichMapResults($toEnrich);
        }

        $failedTeams = 0;
        if ($matchTeamIds !== []) {
            $failedTeams = $this->syncRosters(array_keys($matchTeamIds));
        }

        $statusMsg = 'SUCCESS (' . $upsertedCount . ' match(s) français synchronisé(s), '
            . $enriched . ' enrichi(s) map_results'
            . ($failedTeams > 0 ? ', ' . $failedTeams . ' équipe(s) en échec' : '') . ')';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        return 'Agenda synchronisé ! ' . $upsertedCount . ' match(s) français ajouté(s) en base de données'
            . ($enriched > 0 ? ' (' . $enriched . ' map_results enrichis)' : '')
            . ($failedTeams > 0 ? ' — attention : ' . $failedTeams . ' roster(s) en échec (voir log)' : '') . '.';
    }

    /**
     * Sélectionne les matchs terminés sans résultats par carte, les plus récents
     * d'abord, dans la limite du quota d'appels par exécution. Les matchs déjà
     * enrichis (map_results renseigné) sont exclus : régime permanent = 0 appel.
     */
    private function matchesNeedingEnrichment(array $frenchMatches): array
    {
        $now = time();
        $candidates = [];

        foreach ($frenchMatches as $matchId => $info) {
            $finished = $info['submitted'] !== null || $info['time'] <= $now;
            if ($finished) {
                $candidates[$matchId] = $info['time'];
            }
        }

        if ($candidates === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $this->db->prepare(
            "SELECT match_id FROM etf2l_matches WHERE match_id IN ({$placeholders}) AND map_results IS NULL"
        );
        $stmt->execute(array_keys($candidates));

        $pending = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if ($pending === []) {
            return [];
        }

        // Les plus récents d'abord (visibles en premier sur le site).
        usort($pending, static fn (int $a, int $b): int => ($candidates[$b] ?? 0) <=> ($candidates[$a] ?? 0));

        return array_slice($pending, 0, self::ENRICH_MAX_PER_RUN);
    }

    private function enrichMapResults(array $matchIds): int
    {
        $updateStmt = $this->db->prepare('UPDATE etf2l_matches SET maps = COALESCE(?, maps), r1 = COALESCE(?, r1), r2 = COALESCE(?, r2), map_results = COALESCE(?, map_results) WHERE match_id = ?');
        $enriched = 0;

        foreach ($matchIds as $matchId) {
            try {
                $responseObj = $this->cachedGet(
                    'https://api-v2.etf2l.org/matches/' . (int) $matchId,
                    self::CACHE_TTL_MATCHES
                );

                if ($responseObj === null) {
                    continue;
                }

                $code = isset($responseObj['status']['code']) ? (int) $responseObj['status']['code'] : null;

                if ($code !== 200) {
                    continue;
                }

                $match = $responseObj['match'] ?? null;
                if (!is_array($match)) {
                    continue;
                }

                $maps = $match['maps'] ?? null;
                $r1 = $match['r1'] ?? null;
                $r2 = $match['r2'] ?? null;
                $mapResults = $match['map_results'] ?? null;

                if ($maps === null && $r1 === null && $r2 === null && $mapResults === null) {
                    continue;
                }

                // Match terminé sans détail par carte (forfait...) : on marque avec un
                // tableau vide pour ne pas re-interroger l'API à chaque exécution.
                $mapResultsJson = $mapResults !== null
                    ? json_encode($mapResults, JSON_THROW_ON_ERROR)
                    : (($r1 !== null || $r2 !== null) ? '[]' : null);

                $updateStmt->execute([
                    $maps !== null ? json_encode(array_values((array) $maps), JSON_THROW_ON_ERROR) : null,
                    $r1 !== null ? (int) $r1 : null,
                    $r2 !== null ? (int) $r2 : null,
                    $mapResultsJson,
                    (int) $matchId,
                ]);

                $enriched++;
            } catch (\Throwable $e) {
                error_log('Enrichissement ETF2L match ' . $matchId . ' : ' . $e->getMessage());
                continue;
            }
        }

        return $enriched;
    }

    /**
     * Récupère et stocke les rosters des équipes (FR et adverses) impliquées
     * dans les matchs français, afin de pouvoir comparer les deux équipes.
     *
     * @return int Nombre d'équipes en échec (une équipe fautive ne fait pas
     *             échouer toute la synchronisation).
     */
    private function syncRosters(array $teamIds): int
    {
        $teamStmt = $this->db->prepare('
            REPLACE INTO etf2l_teams (team_id, name, country, tag)
            VALUES (?, ?, ?, ?)
        ');
        $playerStmt = $this->db->prepare('
            REPLACE INTO etf2l_players (team_id, player_id, name, role, country, steamid64)
            VALUES (?, ?, ?, ?, ?, ?)
        ');

        $failed = 0;

        foreach ($teamIds as $teamId) {
            try {
                $responseObj = $this->cachedGet(
                    'https://api-v2.etf2l.org/team/' . (int) $teamId,
                    self::CACHE_TTL_TEAMS
                );
            } catch (\Throwable $e) {
                $failed++;
                error_log('Roster ETF2L équipe ' . $teamId . ' : ' . $e->getMessage());
                continue;
            }

            if ($responseObj === null) {
                continue;
            }

            if (!isset($responseObj['status']['code']) || (int) $responseObj['status']['code'] !== 200) {
                continue;
            }

            $team = $responseObj['team'] ?? null;
            if ($team === null) {
                continue;
            }

            $players = $team['players'] ?? [];

            // Équipe + joueurs en une transaction : écriture atomique et brève.
            $this->db->beginTransaction();

            try {
                $teamStmt->execute([
                    (int) $teamId,
                    $team['name'] ?? 'TBD',
                    isset($team['country']) ? strtolower((string) $team['country']) : 'unknown',
                    $team['tag'] ?? null,
                ]);

                foreach ($players as $p) {
                    $playerStmt->execute([
                        (int) $teamId,
                        (int) ($p['id'] ?? 0),
                        $p['name'] ?? 'Joueur ETF2L',
                        $p['role'] ?? 'Member',
                        isset($p['country']) ? strtolower((string) $p['country']) : 'unknown',
                        isset($p['steam']['id64']) ? (string) $p['steam']['id64'] : null,
                    ]);
                }

                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }

        return $failed;
    }
}
