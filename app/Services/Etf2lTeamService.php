<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Équipes ETF2L (API v2) pour le remplissage assisté de l'outil
 * « Overlay Bracket » : les brackets et classements restent construits à
 * la main, mais les noms, avatars et pays des équipes peuvent être
 * piochés dans une compétition ETF2L — liste des compétitions
 * Highlander récentes, équipes d'une compétition (endpoint
 * /competition/{id}/teams, paginé).
 *
 * Conso modèle Etf2lNameResolver : réponses mises en cache dans la
 * table etf2l_api_cache (clé = URL), appels HTTP espacés par le
 * Etf2lRateLimiter partagé entre tous les processus du site (rate-limit
 * ETF2L : 60 req/min). Un échec (réseau, 429, 5xx…) est mis en cache
 * négativement quelques minutes pour ne pas marteler l'API. La
 * récupération HTTP est injectable (closure) pour les tests.
 */
final class Etf2lTeamService
{
    private const API_URL = 'https://api-v2.etf2l.org';

    /** Durée de vie (s) du cache de la liste des compétitions. */
    private const LIST_TTL_S = 6 * 3600;

    /** Durée de vie (s) du cache des équipes d'une compétition. */
    private const TEAMS_TTL_S = 3600;

    /** Durée de vie (s) du cache négatif (API indisponible, throttle 429…). */
    private const FAIL_TTL_S = 120;

    /** Clé « error » d'une entrée de cache négative (API indisponible). */
    private const NEGATIVE_ERROR = 'hlfr_etf2l_indisponible';

    /** Timeout cURL par appel. */
    private const HTTP_TIMEOUT_S = 15;

    /** Équipes demandées par page d'API. */
    private const TEAMS_PER_PAGE = 100;

    /** Garde-fou : équipes lues au maximum pour une compétition. */
    private const MAX_TEAMS = 300;

    /** Taille max d'un nom d'équipe (octets), comme l'outil bracket. */
    private const MAX_NAME_BYTES = 64;

    /**
     * Récupère une réponse API ETF2L décodée (null si indisponible).
     *
     * @var \Closure(string): (array<string, mixed>|null)
     */
    private \Closure $fetcher;

    /** Espacement des appels HTTP réels, partagé entre tous les processus. */
    private Etf2lRateLimiter $limiter;

    /**
     * @param  \Closure(string): (array<string, mixed>|null)|null  $fetcher  Récupération HTTP injectable (tests)
     * @param  float|null  $httpDelayS  Délai minimal entre deux appels HTTP réels (null = défaut, 0.0 = désactivé en tests)
     */
    public function __construct(?\Closure $fetcher = null, ?float $httpDelayS = null)
    {
        $this->limiter = new Etf2lRateLimiter($httpDelayS);
        $this->fetcher = $fetcher ?? static function (string $url): ?array {
            $meta = JsonClient::getWithMeta($url, self::HTTP_TIMEOUT_S, 'Highlander France Bot/1.0', ['Accept: application/json']);

            // Un throttle 429 (ou toute erreur HTTP) n'est pas un payload :
            // null, pour qu'il ne soit jamais mis en cache comme valide.
            if ($meta['curl_error'] !== '' || $meta['http_code'] !== 200 || ! is_array($meta['data'])) {
                return null;
            }

            return $meta['data'];
        };
    }

    /**
     * Compétitions Highlander des 200 derniers jours, de la plus récente
     * à la plus ancienne (saisons, playoffs, qualifiers, one-night-cups).
     *
     * @return array<int, array{id: int, name: string, archived: bool}>
     */
    public function competitions(): array
    {
        $since = date('Y-m-d', time() - 200 * 86400);
        $payload = $this->cachedFetch(self::API_URL.'/competition/list?limit=100&since='.$since, self::LIST_TTL_S);
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
                'archived' => (bool) ($entry['archived'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Équipes d'une compétition (nom, avatar, pays), triées par nom, sans
     * doublon : de quoi remplir les cases d'un bracket ou les lignes d'un
     * classement. Vide si l'API est indisponible ou la compétition inconnue.
     *
     * @return array<int, array{name: string, avatar: string, country: string}>
     */
    public function teams(int $competitionId): array
    {
        $teams = [];
        $seen = [];
        $page = 1;

        while (count($teams) < self::MAX_TEAMS) {
            $payload = $this->cachedFetch(
                self::API_URL.'/competition/'.$competitionId.'/teams?limit='.self::TEAMS_PER_PAGE.'&page='.$page,
                self::TEAMS_TTL_S,
            );

            $raw = is_array($payload['teams']['data'] ?? null) ? $payload['teams']['data'] : [];
            if ($raw === []) {
                break;
            }

            foreach ($raw as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $id = (int) ($entry['id'] ?? 0);
                if ($id !== 0 && isset($seen[$id])) {
                    continue;
                }

                $name = $this->sanitizeName($entry['name'] ?? null);
                if ($name === null) {
                    continue;
                }

                $steam = is_array($entry['steam'] ?? null) ? $entry['steam'] : [];
                $teams[] = [
                    'name' => $name,
                    'avatar' => $this->cleanUrl($steam['avatar'] ?? null),
                    'country' => $this->clip((string) ($entry['country'] ?? ''), 32),
                ];
                $seen[$id] = true;
            }

            $lastPage = (int) ($payload['teams']['last_page'] ?? 1);
            if ($page >= $lastPage) {
                break;
            }

            $page++;
        }

        usort($teams, static function (array $a, array $b): int {
            return mb_strtolower($a['name']) <=> mb_strtolower($b['name']);
        });

        return $teams;
    }

    /**
     * Appel API avec lecture du cache etf2l_api_cache, écriture après
     * appel. Les équipes ne varient pas en cours de saison : pas de
     * contournement de cache prévu (le rate-limit ETF2L est vite atteint).
     * Un échec (réseau, throttle 429, 5xx…) est mis en cache négativement
     * FAIL_TTL_S : ni payload d'erreur empoisonné pour une heure, ni
     * re-martèlement de l'API à chaque clic pendant une indisponibilité.
     *
     * @return array<string, mixed>|null
     */
    private function cachedFetch(string $url, int $ttl): ?array
    {
        $row = DB::table('etf2l_api_cache')->where('url', $url)->first();
        if ($row !== null) {
            $cached = json_decode((string) $row->payload, true);
            $age = time() - (int) $row->fetched_at;

            if (is_array($cached) && ($cached['error'] ?? null) === self::NEGATIVE_ERROR) {
                // Dernier essai en échec : on n'a pas plus de nouvelles de
                // l'API tant que le cache négatif n'a pas expiré.
                if ($age < self::FAIL_TTL_S) {
                    return null;
                }
            } elseif (is_array($cached) && $age < $ttl) {
                return $cached;
            }
        }

        $this->limiter->wait();

        $payload = ($this->fetcher)($url);

        if ($payload === null || ! $this->isValidPayload($payload)) {
            // Échec (réseau, throttle 429, 5xx…) : cache négatif court.
            DB::table('etf2l_api_cache')->upsert(
                ['url' => $url, 'payload' => json_encode(['error' => self::NEGATIVE_ERROR], JSON_THROW_ON_ERROR), 'fetched_at' => time()],
                ['url'],
                ['payload', 'fetched_at'],
            );

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
     * Un payload n'est exploitable que si l'API a répondu 200 : une
     * réponse de throttle ou d'erreur (status.code 429, 5xx…, corps
     * « Too Many Attempts. ») ne doit jamais finir en cache. Un payload
     * sans bloc status (cache amorcé à la main, fixtures de test) est
     * accepté tel quel.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isValidPayload(array $payload): bool
    {
        $code = $payload['status']['code'] ?? null;

        return $code === null || (int) $code === 200;
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

    /**
     * Coupe une chaîne en octets sans casser un caractère UTF-8.
     */
    private function clip(string $value, int $maxBytes): string
    {
        $value = trim($value);
        if (strlen($value) > $maxBytes) {
            $value = mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }

        return $value;
    }
}
