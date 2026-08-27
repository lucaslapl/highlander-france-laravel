<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;
use App\Services\JsonClient;
use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

/**
 * Calcul du palmarès ETF2L des joueurs inscrits (app:compute-player-palmares).
 *
 * Principe : pour chaque joueur, on récupère la liste de ses saisons via
 * l'API player/results, puis on croise avec les tables de compétition pour
 * obtenir le classement final (champ "ach"). On détecte également les rounds
 * de playoffs significatifs (finales, demi-finales) dans les résultats.
 *
 * Seules les saisons avec un résultat positif sont stockées : podium dans le
 * classement final (ach = 1/2/3) ou participation à un round de playoffs.
 */
final class ComputePlayerPalmaresService
{
    private const SCRIPT_NAME = 'compute_player_palmares.php';

    private const LOCK_FILE = 'compute_player_palmares.lock';

    private const API_CALL_DELAY_S = 1.1;

    private const HTTP_TIMEOUT_S = 15;

    /** TTL du cache des résultats de joueurs (1 semaine). */
    private const CACHE_TTL_RESULTS = 7 * 86400;

    /** TTL du cache des tables de compétition (30 jours, immuables une fois la saison finie). */
    private const CACHE_TTL_TABLES = 30 * 86400;

    private const RESULTS_PER_PAGE = 50;

    private const SEASON_CATEGORIES = [
        'Highlander Season',
        '6v6 Season',
    ];

    private const MODE_MAP = [
        'Highlander' => '9v9',
        '6v6' => '6s',
    ];

    /**
     * Rounds de playoffs significatifs, par ordre de spécificité décroissant.
     * Première regex qui matche dans la chaîne "round" est retenue.
     * Les patterns les plus spécifiques doivent précéder les plus génériques
     * (ex. "quarter.*final" avant "final").
     */
    private const PLAYOFF_PATTERNS = [
        '/grand\s*final/i' => 'Grande Finale',
        '/upper\s*bracket\s*final/i' => 'Finale Upper Bracket',
        '/winner.?s?.?bracket.*final/i' => 'Finale Upper Bracket',
        '/lower\s*bracket\s*final/i' => 'Finale Lower Bracket',
        '/loser.?s?.?bracket.*final/i' => 'Finale Lower Bracket',
        '/bracket\s*final/i' => 'Finale de bracket',
        '/quarter.?final/i' => 'Quart de Finale',
        '/semi.?final/i' => 'Demi-finale',
        '/final/i' => 'Finale',
        '/playoff/i' => 'Playoffs',
    ];

    /**
     * Ordre de prestige des rounds (du moins prestigieux au plus prestigieux).
     * Index 0 = moins prestigieux. Utilisé par betterEntry() et bestPlayoffRound()
     * pour départager deux rounds : index plus bas = moins prestigieux.
     */
    private const PLAYOFF_PRESTIGE = [
        'Playoffs',
        'Quart de Finale',
        'Demi-finale',
        'Finale',
        'Finale de bracket',
        'Finale Lower Bracket',
        'Finale Upper Bracket',
        'Grande Finale',
    ];

    private \PDO $db;

    private float $lastHttpAt = 0;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    // ---------------------------------------------------------------
    // Cache + HTTP
    // ---------------------------------------------------------------

    private function cachedGet(string $url, int $ttl = self::CACHE_TTL_RESULTS): array
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

    // ---------------------------------------------------------------
    // Récupération des données
    // ---------------------------------------------------------------

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

    /**
     * Tables de classement final d'une compétition.
     *
     * @return array<string, array<int, array<string, mixed>>>  division_name => entries
     */
    private function fetchCompetitionTables(int $compId): array
    {
        $url = 'https://api-v2.etf2l.org/competition/'.$compId.'/tables';
        $response = $this->cachedGet($url, self::CACHE_TTL_TABLES);

        return $response['tables'] ?? [];
    }

    // ---------------------------------------------------------------
    // Logique de calcul
    // ---------------------------------------------------------------

    /**
     * Détermine le meilleur round de playoffs atteint par le joueur dans une
     * compétition, et s'il l'a remporté.
     *
     * Parcourt TOUS les matches et retourne le round le plus prestigieux
     * parmi ceux qui matchent une regex de PLAYOFF_PATTERNS.
     *
     * @param  array<int, array<string, mixed>>  $compResults
     * @return array{0: string|null, 1: bool}  [round, won]
     */
    private function bestPlayoffRound(array $compResults): array
    {
        $bestLabel = null;
        $bestWon = false;
        $bestPrestige = PHP_INT_MAX;

        $prestigeMap = array_flip(self::PLAYOFF_PRESTIGE);

        foreach ($compResults as $r) {
            $round = (string) ($r['round'] ?? '');

            foreach (self::PLAYOFF_PATTERNS as $pattern => $label) {
                if (preg_match($pattern, $round) !== 1) {
                    continue;
                }

                $prestige = $prestigeMap[$label] ?? count(self::PLAYOFF_PRESTIGE);

                if ($prestige < $bestPrestige) {
                    $bestPrestige = $prestige;
                    $bestLabel = $label;
                    $bestWon = $this->matchWonByPlayer($r);
                }

                // Passer au match suivant dès qu'un pattern matche
                // (chaque round ne peut correspondre qu'à un seul label).
                break;
            }
        }

        return [$bestLabel, $bestWon];
    }

    /**
     * Le joueur a-t-il gagné ce match ? Utilise was_in_team + scores r1/r2.
     */
    private function matchWonByPlayer(array $result): bool
    {
        $playerSide = null;

        foreach (['clan1', 'clan2'] as $side) {
            $clan = $result[$side] ?? null;
            if (is_array($clan) && ($clan['was_in_team'] ?? false) === true) {
                $playerSide = $side;
                break;
            }
        }

        if ($playerSide === null) {
            return false;
        }

        $r1 = (int) ($result['r1'] ?? 0);
        $r2 = (int) ($result['r2'] ?? 0);

        return $playerSide === 'clan1' ? $r1 > $r2 : $r2 > $r1;
    }

    /**
     * Extrait les informations essentielles d'un résultat API pour le palmarès.
     *
     * @return array{competition_id: int, competition_name: string, competition_category: string, competition_type: string, team_id: int, team_name: string, division_name: string, tier: int, round: string, game_mode: string}|null
     */
    private function extractResultInfo(array $result): ?array
    {
        $type = (string) ($result['competition']['type'] ?? '');
        if (! isset(self::MODE_MAP[$type])) {
            return null;
        }

        $category = (string) ($result['competition']['category'] ?? '');
        if (! in_array($category, self::SEASON_CATEGORIES, true)) {
            return null;
        }

        // Identifier l'équipe du joueur.
        $teamId = 0;
        $teamName = '';
        foreach (['clan1', 'clan2'] as $side) {
            $clan = $result[$side] ?? null;
            if (is_array($clan) && ($clan['was_in_team'] ?? false) === true) {
                $teamId = (int) ($clan['id'] ?? 0);
                $teamName = (string) ($clan['name'] ?? '');
                break;
            }
        }

        if ($teamId <= 0) {
            return null;
        }

        $division = $result['division'] ?? null;
        $divisionName = is_array($division) ? (string) ($division['name'] ?? '') : '';
        $tier = is_array($division) ? (int) ($division['tier'] ?? 0) : 0;

        return [
            'competition_id' => (int) ($result['competition']['id'] ?? 0),
            'competition_name' => (string) ($result['competition']['name'] ?? ''),
            'competition_category' => $category,
            'competition_type' => $type,
            'team_id' => $teamId,
            'team_name' => $teamName,
            'division_name' => $divisionName,
            'tier' => $tier,
            'round' => (string) ($result['round'] ?? ''),
            'game_mode' => self::MODE_MAP[$type],
        ];
    }

    // ---------------------------------------------------------------
    // Run principal
    // ---------------------------------------------------------------

    public function run(): string
    {
        $lock = fopen(hlfr_data_path(self::LOCK_FILE), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return 'Calcul du palmarès ignoré : une autre exécution est déjà en cours.';
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

        $this->db->prepare('DELETE FROM etf2l_api_cache WHERE fetched_at < ?')
            ->execute([time() - max(self::CACHE_TTL_RESULTS, self::CACHE_TTL_TABLES)]);

        $registered = $this->db
            ->query('SELECT steamid FROM players_info WHERE created_at IS NOT NULL ORDER BY steamid')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $computed = 0;
        $failed = 0;
        $errors = [];

        foreach ($registered as $steamid3) {
            try {
                $this->computePlayer((string) $steamid3);
                $computed++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $steamid3.' : '.$e->getMessage();
                error_log('Calcul palmarès joueur '.$steamid3.' : '.$e->getMessage());
            }
        }

        $statusMsg = 'SUCCESS ('.count($registered).' joueur(s) traité(s)'
            .($failed > 0 ? ', '.$failed.' en échec' : '').')';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        $errorReport = '';
        if ($errors !== []) {
            $shown = array_slice($errors, 0, 5);
            $errorReport = "\n\nErreurs (".$failed.") :\n- ".implode("\n- ", $shown)
                .($failed > 5 ? "\n… et ".($failed - 5).' autre(s) (voir log PHP)' : '');
        }

        return 'Palmarès calculé pour '.$computed.' joueur(s) inscrit(s)'
            .($failed > 0 ? ' — attention : '.$failed.' joueur(s) en échec' : '').'.'.$errorReport;
    }

    /**
     * Calcule et stocke le palmarès d'un joueur.
     */
    private function computePlayer(string $steamid3): void
    {
        $steamid64 = SteamId::toSteamId64($steamid3);
        if ($steamid64 === null) {
            return;
        }

        $results = $this->fetchResults($steamid64);
        if ($results === []) {
            $this->db->prepare('DELETE FROM player_palmares WHERE steamid = ?')->execute([$steamid3]);
            return;
        }

        // 1. Extraire et regrouper par compétition.
        $byCompetition = [];

        foreach ($results as $r) {
            $info = $this->extractResultInfo($r);
            if ($info === null || $info['competition_id'] === 0) {
                continue;
            }

            $compId = $info['competition_id'];

            if (! isset($byCompetition[$compId])) {
                $byCompetition[$compId] = [
                    'info' => $info,
                    'matches' => [],
                ];
            }

            $byCompetition[$compId]['matches'][] = $r;
        }

        // 2. Pour chaque compétition, déterminer le placement, le playoff et le temps max.
        $entries = [];

        foreach ($byCompetition as $compId => $comp) {
            $info = $comp['info'];

            $placement = $this->resolvePlacement($compId, $info['team_id']);
            [$playoffRound, $wonPlayoff] = $this->bestPlayoffRound($comp['matches']);

            // Inférer le placement à partir du round de playoffs si non résolu par les tables.
            // Grande Finale gagnée → 1er, perdue → 2ème. Finale gagnée → 1er, perdue → 2ème.
            if ($placement === null && $playoffRound !== null) {
                if ($wonPlayoff && in_array($playoffRound, ['Grande Finale', 'Finale'], true)) {
                    $placement = 1;
                } elseif (! $wonPlayoff && $playoffRound === 'Grande Finale') {
                    $placement = 2;
                }
            }

            if ($placement === null && $playoffRound === null) {
                continue;
            }

            // Timestamp max des matches de cette compétition (tri chronologique).
            $seasonTime = 0;
            foreach ($comp['matches'] as $m) {
                $t = (int) ($m['time'] ?? 0);
                if ($t > $seasonTime) {
                    $seasonTime = $t;
                }
            }

            $entries[] = [
                'steamid' => $steamid3,
                'competition_id' => $compId,
                'game_mode' => $info['game_mode'],
                'competition_name' => $info['competition_name'],
                'team_name' => $info['team_name'],
                'team_id' => $info['team_id'],
                'division_name' => $info['division_name'],
                'tier' => $info['tier'],
                'placement' => $placement,
                'playoff_round' => $playoffRound,
                'won_playoff' => $wonPlayoff ? 1 : 0,
                'season_time' => $seasonTime,
                'computed_at' => time(),
            ];
        }

        // 3. Dédupliquer : si deux entrées appartiennent à la même saison logique
        //    (ex. saison régulière + playoffs séparés), ne garder que la meilleure.
        $entries = $this->deduplicateBySeason($entries);

        // 4. UPSERT dans la table.
        $deleteStmt = $this->db->prepare('DELETE FROM player_palmares WHERE steamid = ?');
        $insertStmt = $this->db->prepare(
            'REPLACE INTO player_palmares
                (steamid, competition_id, game_mode, competition_name, team_name, team_id,
                 division_name, tier, placement, playoff_round, won_playoff, season_time, computed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $this->db->beginTransaction();

        try {
            $deleteStmt->execute([$steamid3]);

            foreach ($entries as $e) {
                $insertStmt->execute([
                    $e['steamid'],
                    $e['competition_id'],
                    $e['game_mode'],
                    $e['competition_name'],
                    $e['team_name'],
                    $e['team_id'],
                    $e['division_name'],
                    $e['tier'],
                    $e['placement'],
                    $e['playoff_round'],
                    $e['won_playoff'],
                    $e['season_time'],
                    $e['computed_at'],
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Déduplique les entrées qui appartiennent à la même saison logique.
     *
     * ETF2L crée parfois deux compétitions distinctes pour une même saison :
     * la saison régulière (ex. "6v6 Season 50 (Autumn 2025)") et les playoffs
     * (ex. "6v6 Season 50 (Autumn 2025): Division 3 Playoffs"). On regroupe
     * par clé de saison normalisée et on ne garde que la meilleure entrée.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateBySeason(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $grouped = [];
        foreach ($entries as $e) {
            $key = $this->seasonKey($e['game_mode'], $e['competition_name']);
            $grouped[$key][] = $e;
        }

        $result = [];
        foreach ($grouped as $group) {
            if (count($group) === 1) {
                $result[] = $group[0];
                continue;
            }

            // Priorité : playoff_round (selon PLAYOFF_PATTERNS) > placement.
            $best = null;
            foreach ($group as $e) {
                if ($best === null) {
                    $best = $e;
                    continue;
                }
                $best = $this->betterEntry($best, $e);
            }

            if ($best !== null) {
                $result[] = $best;
            }
        }

        return $result;
    }

    /**
     * Détermine si $a est meilleur que $b pour le palmarès.
     * Un round de playoffs bat une simple absence de round.
     * Entre deux rounds, celui qui est le plus prestigieux l'emporte.
     * En cas d'égalité round, la victoire compte. Sinon, le placement le plus bas.
     */
    private function betterEntry(array $a, array $b): array
    {
        $roundA = $a['playoff_round'];
        $roundB = $b['playoff_round'];
        $placeA = $a['placement'];
        $placeB = $b['placement'];

        // Un round de playoffs est toujours meilleur qu'aucun round.
        if ($roundA !== null && $roundB === null) {
            return $a;
        }
        if ($roundB !== null && $roundA === null) {
            return $b;
        }

        // Les deux ont un round : comparer le prestige.
        if ($roundA !== null && $roundB !== null) {
            $idxA = array_search($roundA, self::PLAYOFF_PRESTIGE, true);
            $idxB = array_search($roundB, self::PLAYOFF_PRESTIGE, true);
            $idxA = $idxA !== false ? $idxA : count(self::PLAYOFF_PRESTIGE);
            $idxB = $idxB !== false ? $idxB : count(self::PLAYOFF_PRESTIGE);

            if ($idxA !== $idxB) {
                return $idxA < $idxB ? $a : $b; // Index plus bas = plus prestigieux.
            }

            // Même round : préférer celui qui l'a gagné.
            if ($a['won_playoff'] !== $b['won_playoff']) {
                return $a['won_playoff'] ? $a : $b;
            }

            // Même round, même résultat : préférer l'entrée qui a un round de playoffs
            // (compétition playoffs) sur celle qui n'en a pas (saison régulière + placement).
            $aHasRound = $a['playoff_round'] !== null;
            $bHasRound = $b['playoff_round'] !== null;
            if ($aHasRound !== $bHasRound) {
                return $aHasRound ? $a : $b;
            }
        }

        // Pas de round, ou rounds identiques : comparer le placement.
        if ($placeA !== null && $placeB === null) {
            return $a;
        }
        if ($placeB !== null && $placeA === null) {
            return $b;
        }
        if ($placeA !== null && $placeB !== null) {
            return $placeA <= $placeB ? $a : $b; // Plus bas = mieux.
        }

        // Aucun des deux n'a ni round ni placement — garder le plus récent.
        return ($a['season_time'] ?? 0) >= ($b['season_time'] ?? 0) ? $a : $b;
    }

    /**
     * Clé de saison normalisée pour détecter les doublons.
     *
     * Extrait le nom de base de la saison en retirant les parenthèses
     * (ex. "(Autumn 2025)") et les suffixes après ":" (ex. ": Division 3 Playoffs").
     * Cela permet de regrouper "6v6 Season 50 (Autumn 2025)" et
     * "6v6 Season 50 (Autumn 2025): Division 3 Playoffs" sous la même clé.
     */
    private function seasonKey(string $gameMode, string $competitionName): string
    {
        $name = $competitionName;
        // Retirer les parenthèses et leur contenu : "(Spring 2026)", "(Autumn 2025)", etc.
        $name = preg_replace('/\s*\([^)]*\)/u', '', $name);
        // Retirer les suffixes après ":" : ": Premiership", ": Division 3 Playoffs", etc.
        $name = preg_replace('/\s*:.*$/u', '', $name);
        $name = trim($name);

        return $gameMode.'|'.$name;
    }

    /**
     * Récupère le classement final (champ "ach") d'une équipe dans une compétition.
     */
    private function resolvePlacement(int $compId, int $teamId): ?int
    {
        $tables = $this->fetchCompetitionTables($compId);

        foreach ($tables as $divisionEntries) {
            foreach ($divisionEntries as $entry) {
                if ((int) ($entry['id'] ?? 0) === $teamId) {
                    $ach = $entry['ach'] ?? null;
                    return is_int($ach) && $ach >= 1 && $ach <= 3 ? $ach : null;
                }
            }
        }

        return null;
    }
}
