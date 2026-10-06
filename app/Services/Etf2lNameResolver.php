<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Résolution des pseudos ETF2L à partir des SteamID des joueurs en jeu
 * (endpoint POST /api/server/etf2l-names, consommé par le plugin SourceMod
 * hlfr_etf2l_rename, et overlays OBS via OverlayStatsService).
 *
 * Ordre de résolution (aucune requête réseau tant que possible) :
 *  1. la table etf2l_players (rosters ETF2L déjà synchronisés) ;
 *  2. le cache API persistant (table etf2l_api_cache) ;
 *  3. l'API ETF2L v2 (player/{steamid64}), avec écriture du cache après
 *     chaque appel — y compris pour les « joueur introuvable » (cache
 *     négatif) afin de ne pas marteler l'API pour les mercs sans compte.
 *
 * Les pseudos sont assainis avant renvoi (suppression des caractères de
 * contrôle, taille plafonnée) : ils seront affichés en jeu comme noms de
 * joueurs. La récupération HTTP est injectable (closure) pour les tests.
 */
final class Etf2lNameResolver
{
    private const API_URL = 'https://api-v2.etf2l.org/player/';

    /** Durée de vie (s) d'une réponse API « joueur trouvé ». */
    private const CACHE_TTL_FOUND_S = 24 * 3600;

    /** Durée de vie (s) du cache négatif (joueur introuvable sur ETF2L). */
    private const CACHE_TTL_NOT_FOUND_S = 12 * 3600;

    /** Délai minimal entre deux appels HTTP réels (rate-limit ETF2L : 60 req/min). */
    private const HTTP_DELAY_S = 1.1;

    /** Timeout cURL par appel. */
    private const HTTP_TIMEOUT_S = 10;

    /** Taille max d'un pseudo renvoyé aux serveurs (MAX_NAME_LENGTH SourceMod - 1). */
    private const MAX_NAME_BYTES = 32;

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
     * Résolution des pseudos ETF2L d'une liste de SteamID3 « [U:1:…] »
     * (format des clés de l'API logs.tf), avec le même ordre de résolution
     * que resolve() : table etf2l_players, cache API, puis API ETF2L.
     *
     * @param  array<int, string>  $steamids3  SteamID3 « [U:1:…] » (les entrées invalides sont ignorées)
     * @return array<string, array{name: string, etf2l_id: int|null}> pseudos trouvés, indexés par le SteamID3 d'origine
     */
    public function resolveBySteamId3(array $steamids3): array
    {
        // steamid3 → steamid2 (le resolve() interne regroupe par SteamID64,
        // les alias éventuels sont donc gérés naturellement).
        $aliases = [];
        $steamids2 = [];

        foreach ($steamids3 as $steamid3) {
            $steamid64 = SteamId::toSteamId64((string) $steamid3);
            if ($steamid64 === null) {
                continue;
            }

            $steamid2 = SteamId::toSteam2($steamid64);
            $aliases[$steamid2][] = (string) $steamid3;
            $steamids2[] = $steamid2;
        }

        $out = [];

        foreach ($this->resolve($steamids2) as $steamid2 => $hit) {
            foreach ($aliases[$steamid2] ?? [] as $steamid3) {
                $out[$steamid3] = $hit;
            }
        }

        return $out;
    }

    /**
     * Résout les pseudos ETF2L d'une liste de SteamID2.
     *
     * @param  array<int, mixed>  $steamids2  SteamID2 « STEAM_1:0:… » (les entrées invalides sont ignorées)
     * @return array<string, array{name: string, etf2l_id: int|null}> pseudos trouvés, indexés par le SteamID2 d'origine
     */
    public function resolve(array $steamids2): array
    {
        // Regroupement par SteamID64 : un joueur peut être référencé sous
        // plusieurs alias SteamID2 (STEAM_0 / STEAM_1).
        $by64 = [];
        foreach ($steamids2 as $steamid2) {
            if (! is_string($steamid2)) {
                continue;
            }

            $steamid64 = SteamId::fromSteam2($steamid2);
            if ($steamid64 === null) {
                continue;
            }

            $by64[$steamid64][] = $steamid2;
        }

        if ($by64 === []) {
            return [];
        }

        /** @var array<string, array{name: string, etf2l_id: int|null}> $resolved */
        $resolved = [];
        /** @var array<string, true> $missing joueurs pas encore résolus */
        $missing = array_fill_keys(array_keys($by64), true);
        /** @var array<string, true> $negative joueurs absents de l'ETF2L (cache négatif) */
        $negative = [];

        $this->resolveFromEtf2lPlayers($missing, $resolved);
        $this->resolveFromApiCache($missing, $negative, $resolved);
        $this->resolveFromApi($missing, $negative, $resolved);

        // Repli vers les SteamID2 d'origine (uniquement les joueurs trouvés).
        $out = [];
        foreach ($by64 as $steamid64 => $aliases) {
            $hit = $resolved[$steamid64] ?? null;
            if ($hit === null) {
                continue;
            }

            foreach ($aliases as $alias) {
                $out[$alias] = $hit;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------
    // Niveau 1 : table etf2l_players (rosters déjà synchronisés)
    // ---------------------------------------------------------------

    /**
     * @param  array<string, true>  $missing  modifié en place (les résolus sont retirés)
     * @param  array<string, array{name: string, etf2l_id: int|null}>  $resolved  complété en place
     */
    private function resolveFromEtf2lPlayers(array &$missing, array &$resolved): void
    {
        if ($missing === []) {
            return;
        }

        $rows = DB::table('etf2l_players')
            ->whereIn('steamid64', array_keys($missing))
            ->whereNotNull('name')
            ->get(['player_id', 'name', 'steamid64']);

        foreach ($rows as $row) {
            $steamid64 = (string) $row->steamid64;

            if (! isset($missing[$steamid64])) {
                continue;
            }

            $name = $this->sanitizeName($row->name);
            if ($name === null) {
                continue;
            }

            $resolved[$steamid64] = ['name' => $name, 'etf2l_id' => (int) $row->player_id];
            unset($missing[$steamid64]);
        }
    }

    // ---------------------------------------------------------------
    // Niveau 2 : cache persistant de l'API (etf2l_api_cache)
    // ---------------------------------------------------------------

    /**
     * @param  array<string, true>  $missing  modifié en place
     * @param  array<string, true>  $negative  complété (absents, freshness 12 h)
     * @param  array<string, array{name: string, etf2l_id: int|null}>  $resolved  complété
     */
    private function resolveFromApiCache(array &$missing, array &$negative, array &$resolved): void
    {
        if ($missing === []) {
            return;
        }

        $urls = [];
        foreach (array_keys($missing) as $steamid64) {
            $urls[] = self::API_URL.$steamid64;
        }

        $rows = DB::table('etf2l_api_cache')
            ->whereIn('url', $urls)
            ->where('fetched_at', '>', time() - self::CACHE_TTL_FOUND_S)
            ->get(['url', 'payload', 'fetched_at']);

        foreach ($rows as $row) {
            $steamid64 = substr((string) $row->url, strlen(self::API_URL));

            if (! isset($missing[$steamid64])) {
                continue;
            }

            $decoded = json_decode((string) $row->payload, true);
            if (! is_array($decoded)) {
                // Payload corrompu : on le traite comme absent du cache
                // (le niveau 3 le re-récupérera et écrasera la ligne).
                continue;
            }

            $hit = $this->hitFromPayload($decoded);
            if ($hit !== null) {
                // Fraîcheur standard (24 h) pour un joueur trouvé.
                $resolved[$steamid64] = $hit;
                unset($missing[$steamid64]);

                continue;
            }

            if ($this->isNotFoundPayload($decoded)) {
                // Fraîcheur réduite (12 h) pour un joueur introuvable.
                if ((int) $row->fetched_at <= time() - self::CACHE_TTL_NOT_FOUND_S) {
                    continue;
                }

                $negative[$steamid64] = true;
                unset($missing[$steamid64]);
            }
        }
    }

    // ---------------------------------------------------------------
    // Niveau 3 : API ETF2L (rate-limitée), avec écriture du cache
    // ---------------------------------------------------------------

    /**
     * @param  array<string, true>  $missing  modifié en place
     * @param  array<string, true>  $negative  complété
     * @param  array<string, array{name: string, etf2l_id: int|null}>  $resolved  complété
     */
    private function resolveFromApi(array &$missing, array &$negative, array &$resolved): void
    {
        foreach (array_keys($missing) as $steamid64) {
            // Un échec réseau ne doit pas bloquer la résolution des autres.
            $payload = $this->cachedFetch(self::API_URL.$steamid64);

            if ($payload === null) {
                continue;
            }

            $hit = $this->hitFromPayload($payload);
            if ($hit !== null) {
                $resolved[$steamid64] = $hit;
                unset($missing[$steamid64]);

                continue;
            }

            if ($this->isNotFoundPayload($payload)) {
                $negative[$steamid64] = true;
                unset($missing[$steamid64]);
            }
        }
    }

    /**
     * Appel API réel (rate-limité) avec écriture immédiate dans le cache
     * etf2l_api_cache — y compris pour une réponse 404 (cache négatif) ;
     * seules les erreurs réseau / réponses illisibles restent sans cache
     * afin d'être retentées au prochain appel.
     */
    private function cachedFetch(string $url): ?array
    {
        $elapsed = microtime(true) - $this->lastHttpAt;
        if ($this->lastHttpAt > 0 && $elapsed < self::HTTP_DELAY_S) {
            usleep((int) ((self::HTTP_DELAY_S - $elapsed) * 1e6));
        }
        $this->lastHttpAt = microtime(true);

        $payload = ($this->fetcher)($url);
        if ($payload === null) {
            return null;
        }

        // Cache négatif : l'API renvoie un corps JSON complet même en 404.
        if (! $this->hitFromPayload($payload) && ! $this->isNotFoundPayload($payload)) {
            // Réponse 200 sans pseudo exploitable, ou code inattendu :
            // rien à mettre en cache (sujet à re-fetch).
            return $payload;
        }

        $this->writeCache($url, $payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeCache(string $url, array $payload): void
    {
        DB::table('etf2l_api_cache')->upsert(
            ['url' => $url, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'fetched_at' => time()],
            ['url'],
            ['payload', 'fetched_at'],
        );
    }

    /**
     * Extrait {name, etf2l_id} d'un payload API player, ou null si absent.
     *
     * @param  array<string, mixed>  $payload
     * @return array{name: string, etf2l_id: int|null}|null
     */
    private function hitFromPayload(array $payload): ?array
    {
        $player = $payload['player'] ?? null;
        if (! is_array($player)) {
            return null;
        }

        $name = $this->sanitizeName($player['name'] ?? null);
        if ($name === null) {
            return null;
        }

        $id = $player['id'] ?? null;

        return ['name' => $name, 'etf2l_id' => is_numeric($id) ? (int) $id : null];
    }

    /**
     * Un payload est-il une réponse « joueur introuvable » de l'API
     * (bloc status 404) ?
     *
     * @param  array<string, mixed>  $payload
     */
    private function isNotFoundPayload(array $payload): bool
    {
        return (int) ($payload['status']['code'] ?? 0) === 404;
    }

    /**
     * Assainit un pseudo avant renvoi aux serveurs de jeu : suppression des
     * caractères de contrôle, espaces compressés, taille plafonnée (sans
     * couper un caractère UTF-8).
     */
    private function sanitizeName(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $name = preg_replace('/[\x{0000}-\x{001F}\x{007F}]/u', '', $name);
        if ($name === null) {
            // UTF-8 invalide.
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
}
