<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Client lecture seule de l'API tf2esports (https://tf2esports.com/api/v1).
 *
 * L'API agrège les données du TF2 compétitif (ETF2L, RGL, ozfortress…) :
 * classements, équipes, joueurs, matchs et stats par carte. Réservée aux
 * utilisateurs invités ; la clé s'envoie en header X-API-Key (ou Bearer).
 *
 * Contraintes observées sur le terrain :
 *  - rate-limit 60 req/min/clé (pas de header de quota sous le seuil) → on
 *    espace les appels (~1,2 s) et on met en cache les résultats en JSON
 *    sous hlfr_data_path().
 *  - typage « mou » : la plupart des nombres sont des chaînes, et les ID
 *    sont des chaînes (sauf certains ints). Toujours caster avant comparaison.
 *
 * Le transport est injectable (closure) pour permettre les tests sans réseau.
 */
final class Tf2EsportsApi
{
    private const BASE_URL = 'https://tf2esports.com/api/v1';

    /** Durée de vie du cache JSON des réponses (réutilisé sans re-appeler l'API). */
    private const CACHE_TTL_S = 600;

    /** Délai minimal entre deux appels HTTP réels (rate-limit 60 req/min). */
    private const HTTP_DELAY_S = 1.2;

    /** Timeout cURL par appel. */
    private const HTTP_TIMEOUT_S = 15;

    private readonly \Closure $transport;

    /** Timestamp (microtime) du dernier appel HTTP réel, pour le rate-limit. */
    private float $lastHttpAt = 0;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport
            ?? static fn (string $url): array => JsonClient::getWithMeta(
                $url,
                self::HTTP_TIMEOUT_S,
                'Highlander France/1.0',
                ['X-API-Key: '.config('hlfr.tf2esports_api_key'), 'Accept: application/json']
            );
    }

    /** Classement actuel des équipes (scope rankings). */
    public function rankings(array $params = []): array
    {
        return $this->get('/rankings', $params);
    }

    /** Profil d'une équipe + classements + infos compétition (scope matches). */
    public function teamProfile(int $id): array
    {
        return $this->get('/teams/'.$id);
    }

    /** Historique des matchs d'une équipe (scope matches). */
    public function teamMatches(int $id, array $params = []): array
    {
        return $this->get('/teams/'.$id.'/matches', $params);
    }

    /** Recherche de matchs avec filtres (team, event, status, format…) (scope matches). */
    public function matches(array $params = []): array
    {
        return $this->get('/matches', $params);
    }

    /** Face-à-face entre deux équipes (scope matches). */
    public function headToHead(int $teamA, int $teamB): array
    {
        return $this->get('/stats/head-to-head', ['team_a' => $teamA, 'team_b' => $teamB]);
    }

    /** Statistiques agrégées d'un joueur (scope players). */
    public function playerStats(int $id): array
    {
        return $this->get('/players/'.$id.'/stats');
    }

    /** Leaderboard pondéré par division (scope players). */
    public function leaderboard(array $params = []): array
    {
        return $this->get('/leaderboard', $params);
    }

    /** Fréquence de jeu des cartes (scope maps). */
    public function maps(array $params = []): array
    {
        return $this->get('/stats/maps', $params);
    }

    /** Vue d'ensemble du dataset (compteurs, date de dernier recalcul). */
    public function meta(): array
    {
        return $this->get('/stats/meta');
    }

    /**
     * Appel GET vers un chemin API, avec cache JSON et rate-limit.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException si l'API répond avec une erreur (401/403/404/429…) ou une non-réponse.
     */
    private function get(string $path, array $query = []): array
    {
        $url = self::BASE_URL.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $cacheFile = hlfr_data_path('tf2esports_'.md5($url).'.json');
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL_S) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $this->throttle();

        $meta = ($this->transport)($url);

        if (($meta['curl_error'] ?? '') !== '') {
            throw new \RuntimeException('Erreur cURL tf2esports ('.$url.') : '.$meta['curl_error']);
        }

        $httpCode = (int) ($meta['http_code'] ?? 0);
        $data = $meta['data'] ?? null;

        if ($httpCode >= 400) {
            $message = is_array($data) ? (string) ($data['message'] ?? '') : '';
            throw new \RuntimeException('tf2esports HTTP '.$httpCode.' ('.$url.')'.$message);
        }

        if (! is_array($data)) {
            throw new \RuntimeException('Réponse non-JSON de tf2esports ('.$url.').');
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
