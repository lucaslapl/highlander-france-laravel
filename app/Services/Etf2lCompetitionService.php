<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Import des compétitions ETF2L (API v2) pour l'outil d'overlay bracket /
 * classement : liste des compétitions Highlander récentes, résultats d'une
 * compétition (bracket de playoffs) et tables de classement par division.
 *
 * Conso modèle Etf2lNameResolver : réponses mises en cache dans la table
 * etf2l_api_cache (clé = URL), TTL courts pour results/tables (données
 * appelées à bouger en fin de saison), appels HTTP espacés de plus d'une
 * seconde (rate-limit ETF2L : 60 req/min). La récupération HTTP est
 * injectable (closure) pour les tests.
 */
final class Etf2lCompetitionService
{
    private const API_URL = 'https://api-v2.etf2l.org';

    /** Durée de vie (s) du cache de la liste des compétitions. */
    private const LIST_TTL_S = 6 * 3600;

    /** Durée de vie (s) du cache des résultats / tables d'une compétition. */
    private const DATA_TTL_S = 10 * 60;

    /** Délai minimal entre deux appels HTTP réels (rate-limit ETF2L : 60 req/min). */
    private const HTTP_DELAY_S = 1.1;

    /** Timeout cURL par appel. */
    private const HTTP_TIMEOUT_S = 15;

    /** Taille max d'un nom d'équipe stocké dans un overlay. */
    private const MAX_NAME_BYTES = 64;

    /**
     * Récupère une réponse API ETF2L décodée (null si indisponible).
     *
     * @var \Closure(string): (array|null)
     */
    private \Closure $fetcher;

    /** Timestamp (microtime) du dernier appel HTTP réel, pour le rate-limit. */
    private float $lastHttpAt = 0;

    /**
     * @param  \Closure(string): (array|null)|null  $fetcher  Récupération HTTP injectable (tests)
     */
    public function __construct(?\Closure $fetcher = null)
    {
        $this->fetcher = $fetcher ?? static function (string $url): ?array {
            $meta = JsonClient::getWithMeta($url, self::HTTP_TIMEOUT_S, 'Highlander France Bot/1.0', ['Accept: application/json']);

            if ($meta['curl_error'] !== '' || ! is_array($meta['data'])) {
                return null;
            }

            return $meta['data'];
        };
    }

    /**
     * Compétitions Highlander des 200 derniers jours, de la plus récente à
     * la plus ancienne (saisons, playoffs, qualifiers, one-night-cups).
     *
     * @return array<int, array{id: int, name: string, category: string, archived: bool}>
     */
    public function competitions(): array
    {
        $since = date('Y-m-d', time() - 200 * 86400);
        $payload = $this->cachedFetch(self::API_URL.'/competition/list?limit=100&since='.$since, self::LIST_TTL_S, false);
        $raw = is_array($payload['competitions']['data'] ?? null) ? $payload['competitions']['data'] : [];

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? '') !== 'Highlander') {
                continue;
            }

            $name = $this->sanitizeName($entry['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $out[] = [
                'id' => (int) ($entry['id'] ?? 0),
                'name' => $name,
                'category' => (string) ($entry['category'] ?? ''),
                'archived' => (bool) ($entry['archived'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Résultats d'une compétition, normalisés pour l'import de bracket :
     * un match par entrée, équipes assainies et scores sous forme de chaîne
     * ('' si pas encore joué).
     *
     * @return array<int, array{round: string, time: int, teams: array<int, array{name: string, avatar: string, country: string, score: string}>}>
     */
    public function results(int $competitionId, bool $force = false): array
    {
        $payload = $this->cachedFetch(self::API_URL.'/competition/'.$competitionId.'/results?limit=200', self::DATA_TTL_S, $force);
        $raw = is_array($payload['results']['data'] ?? null) ? $payload['results']['data'] : [];

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $teams = [
                $this->normalizeTeam($entry['clan1'] ?? null, $entry['r1'] ?? null),
                $this->normalizeTeam($entry['clan2'] ?? null, $entry['r2'] ?? null),
            ];
            if ($teams[0]['name'] === '' || $teams[1]['name'] === '') {
                continue;
            }

            $out[] = [
                'round' => trim((string) ($entry['round'] ?? '')),
                'time' => (int) ($entry['time'] ?? 0),
                'teams' => $teams,
            ];
        }

        return $out;
    }

    /**
     * Tables de classement d'une compétition, indexées par nom de division,
     * dans l'ordre renvoyé par l'API (déjà classées).
     *
     * @return array<string, array<int, array{name: string, country: string, played: int, won: int, lost: int, score: int, penalty: int}>>
     */
    public function tables(int $competitionId, bool $force = false): array
    {
        $payload = $this->cachedFetch(self::API_URL.'/competition/'.$competitionId.'/tables', self::DATA_TTL_S, $force);
        $raw = is_array($payload['tables'] ?? null) ? $payload['tables'] : [];

        $out = [];
        foreach ($raw as $division => $rows) {
            $division = trim((string) $division);
            if ($division === '' || ! is_array($rows)) {
                continue;
            }

            $normalized = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $name = $this->sanitizeName($row['name'] ?? null);
                if ($name === null) {
                    continue;
                }

                $normalized[] = [
                    'name' => $name,
                    'country' => trim((string) ($row['country'] ?? '')),
                    'played' => (int) ($row['maps_played'] ?? 0),
                    'won' => (int) ($row['maps_won'] ?? 0),
                    'lost' => (int) ($row['maps_lost'] ?? 0),
                    'score' => (int) ($row['score'] ?? 0),
                    'penalty' => (int) ($row['penalty_points'] ?? 0),
                ];
            }

            if ($normalized !== []) {
                $out[$division] = $normalized;
            }
        }

        return $out;
    }

    /**
     * Regroupe des résultats normalisés en colonnes de bracket, une par
     * round : rounds triés par date du premier match, matches triés par
     * date au sein du round, voie (upper/lower/final) déduite du nom.
     *
     * Fonction pure (aucune I/O) : testée isolément, réutilisée par les
     * imports admin et les re-synchronisations.
     *
     * @param  array<int, array{round: string, time: int, teams: array<int, array{name: string, avatar: string, country: string, score: string}>}>  $matches
     * @return array<int, array{label: string, lane: 'upper'|'lower'|'final', matches: array<int, array{teams: array<int, array{name: string, avatar: string, country: string, score: string}>}>}>
     */
    public static function bracketColumns(array $matches): array
    {
        $byRound = [];
        foreach ($matches as $match) {
            $round = trim((string) ($match['round'] ?? ''));
            if ($round === '') {
                $round = 'Round';
            }

            $byRound[$round][] = $match;
        }

        // Tri des rounds par date du premier match, l'ordre d'affichage du
        // bracket suit le déroulé du tournoi (quarts → demies → finale…).
        uasort($byRound, static function (array $a, array $b): int {
            $ta = PHP_INT_MAX;
            $tb = PHP_INT_MAX;
            foreach ($a as $match) {
                $ta = min($ta, (int) ($match['time'] ?? 0));
            }
            foreach ($b as $match) {
                $tb = min($tb, (int) ($match['time'] ?? 0));
            }

            return $ta <=> $tb;
        });

        $columns = [];
        foreach ($byRound as $round => $roundMatches) {
            usort($roundMatches, static fn (array $a, array $b): int => ((int) ($a['time'] ?? 0)) <=> ((int) ($b['time'] ?? 0)));

            $columnMatches = [];
            foreach ($roundMatches as $match) {
                $columnMatches[] = ['teams' => $match['teams']];
            }

            $columns[] = [
                'label' => $round,
                'lane' => self::roundLane($round),
                'matches' => $columnMatches,
            ];
        }

        return $columns;
    }

    /**
     * Voie d'affichage d'un round : lower bracket si le nom le dit, final
     * pour la grande finale, upper bracket sinon (les « Upper Bracket
     * Final » et autres demi-finales restent dans la voie haute).
     *
     * @return 'upper'|'lower'|'final'
     */
    public static function roundLane(string $round): string
    {
        $round = strtolower(trim($round));

        if (str_contains($round, 'lower')) {
            return 'lower';
        }

        if ($round === 'final' || $round === 'grand final' || str_starts_with($round, 'grand final')) {
            return 'final';
        }

        return 'upper';
    }

    /**
     * Normalise une équipe d'un résultat API (clan) : nom assaini, avatar
     * et pays conservés, score sous forme de chaîne ('' si non joué).
     *
     * @return array{name: string, avatar: string, country: string, score: string}
     */
    private function normalizeTeam(mixed $clan, mixed $score): array
    {
        $name = is_array($clan) ? $this->sanitizeName($clan['name'] ?? null) : null;
        $avatar = '';
        $country = '';

        if (is_array($clan)) {
            $avatar = $this->cleanUrl($clan['steam']['avatar'] ?? null);
            $country = trim((string) ($clan['country'] ?? ''));
        }

        $score = is_numeric($score) ? (string) (int) $score : '';

        return [
            'name' => $name ?? '',
            'avatar' => $avatar,
            'country' => $country,
            'score' => $score,
        ];
    }

    /**
     * Appel API avec lecture du cache etf2l_api_cache (le paramètre force
     * le contourne pour les re-synchronisations), écriture après appel.
     *
     * @return array<string, mixed>|null
     */
    private function cachedFetch(string $url, int $ttl, bool $force): ?array
    {
        if (! $force) {
            $row = DB::table('etf2l_api_cache')->where('url', $url)->first();
            if ($row !== null && (time() - (int) $row->fetched_at) < $ttl) {
                $cached = json_decode((string) $row->payload, true);

                return is_array($cached) ? $cached : null;
            }
        }

        $elapsed = microtime(true) - $this->lastHttpAt;
        if ($this->lastHttpAt > 0 && $elapsed < self::HTTP_DELAY_S) {
            usleep((int) ((self::HTTP_DELAY_S - $elapsed) * 1e6));
        }
        $this->lastHttpAt = microtime(true);

        $payload = ($this->fetcher)($url);
        if ($payload === null) {
            return null;
        }

        DB::table('etf2l_api_cache')->upsert(
            ['url' => $url, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'fetched_at' => time()],
            ['url'],
            ['payload', 'fetched_at'],
        );

        return $payload;
    }

    /**
     * Assainit un nom d'équipe : caractères de contrôle supprimés, espaces
     * compressés, taille plafonnée sans couper un caractère UTF-8.
     */
    private function sanitizeName(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $name = preg_replace('/[\x{0000}-\x{001F}\x{007F}]/u', '', $name);
        if ($name === null) {
            return null;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            return null;
        }

        if (strlen($name) > self::MAX_NAME_BYTES) {
            $name = mb_strcut($name, 0, self::MAX_NAME_BYTES, 'UTF-8');
        }

        return $name;
    }

    /**
     * Garde uniquement une URL d'avatar http(s) sensée.
     */
    private function cleanUrl(mixed $url): string
    {
        $url = trim((string) (is_string($url) ? $url : ''));
        if ($url === '' || ! preg_match('~^https?://[\w.-]+~i', $url)) {
            return '';
        }

        if (strlen($url) > 500) {
            return '';
        }

        return $url;
    }
}
