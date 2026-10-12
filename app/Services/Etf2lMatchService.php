<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Matchs ETF2L (API v2, endpoint /matches/{id}) pour l'outil « Overlay
 * Scores » : un lien de match (https://etf2l.org/matches/{id}/) suffit à
 * remplir une série — titre, noms d'équipes (acronyme « tag » ETF2L le
 * cas échéant), avatars, rosters et maps jouées si ETF2L les connaît.
 *
 * Conso modèle Etf2lTeamService : réponses mises en cache dans la table
 * etf2l_api_cache (clé = URL), appels HTTP espacés par le Etf2lRateLimiter
 * partagé entre tous les processus du site (rate-limit ETF2L : 60 req/min).
 * Un échec (réseau, 429, 5xx…) est mis en cache négativement quelques
 * minutes pour ne pas marteler l'API. La récupération HTTP est injectable
 * (closure) pour les tests.
 *
 * Le TTL est court : les maps d'un match de playoffs ne sont révélées
 * qu'au pick / ban et les équipes « TBD » qu'à l'issue du tour précédent
 * — la resynchronisation d'une série doit voir ces changements sans
 * attendre une heure.
 *
 * Le payload d'un match est défensif par nature (l'API ne garantit pas la
 * présence du tag, du type de compétition ou des équipes) : chaque champ
 * manque à l'appel sans faire échouer les autres, la saisie manuelle de
 * l'outil reste le repli.
 */
final class Etf2lMatchService
{
    private const API_URL = 'https://api-v2.etf2l.org/matches/';

    /** Durée de vie (s) du cache d'un match (maps / équipes révélées en cours de route). */
    private const MATCH_TTL_S = 900;

    /** Durée de vie (s) du cache négatif (API indisponible, throttle 429…). */
    private const FAIL_TTL_S = 120;

    /** Clé « error » d'une entrée de cache négative (API indisponible). */
    private const NEGATIVE_ERROR = 'hlfr_etf2l_indisponible';

    /** Timeout cURL par appel. */
    private const HTTP_TIMEOUT_S = 15;

    /** Taille max d'un nom ou d'un tag (octets), comme Etf2lTeamService. */
    private const MAX_NAME_BYTES = 64;

    /**
     * Récupère une réponse API ETF2L décodée (null si indisponible).
     *
     * @var \Closure(string): (array<string, mixed>|null)
     */
    private \Closure $fetcher;

    /** Espacement des appels HTTP réels, partagé entre tous les processus. */
    private Etf2lRateLimiter $limiter;

    /** Rosters des équipes du match (service partagé avec le remplissage assisté). */
    private Etf2lTeamService $teams;

    /**
     * @param  \Closure(string): (array<string, mixed>|null)|null  $fetcher  Récupération HTTP injectable (tests)
     * @param  float|null  $httpDelayS  Délai minimal entre deux appels HTTP réels (null = défaut, 0.0 = désactivé en tests)
     */
    public function __construct(?\Closure $fetcher = null, ?float $httpDelayS = null)
    {
        $this->limiter = new Etf2lRateLimiter($httpDelayS);
        $this->teams = new Etf2lTeamService($fetcher, $httpDelayS);

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
     * Identifiant de match extrait d'un lien ETF2L
     * (https://etf2l.org/matches/93233/), ou null si l'URL n'en est pas un.
     */
    public static function matchIdFromUrl(string $url): ?int
    {
        if (preg_match('~^(?:https?://)?(?:www\.)?etf2l\.org/matches/(\d+)/?~i', trim($url), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Match ETF2L normalisé, ou null si l'API est indisponible ou le match
     * inconnu. Champs :
     *
     *  - id, url         : identifiant et lien du match ;
     *  - competition     : {name, type} — type « Highlander » / « 6v6 » si
     *                      l'API le remonte, sinon chaîne vide ;
     *  - category        : « 9v9 » (Highlander), « 6v6 », ou null si le
     *                      format du match reste inconnu ;
     *  - teams           : deux entrées (clan1 puis clan2), null quand
     *                      l'équipe est encore inconnue (TBD en playoffs),
     *                      sinon {id, name, tag, avatar} — le tag est
     *                      l'acronyme affiché sur ETF2L, vide si absent ;
     *  - maps            : maps jouées si ETF2L les connaît (nom de map
     *                      complet, ex. « koth_product_final »), sinon [] ;
     *  - title           : titre de série généré (« Compétition — A vs B »).
     *
     * @return array<string, mixed>|null
     */
    public function match(int $matchId): ?array
    {
        if ($matchId <= 0) {
            return null;
        }

        $payload = $this->cachedFetch(self::API_URL.$matchId, self::MATCH_TTL_S);
        $match = is_array($payload['match'] ?? null) ? $payload['match'] : null;
        if ($match === null) {
            return null;
        }

        $competition = is_array($match['competition'] ?? null) ? $match['competition'] : [];
        $type = (string) ($competition['type'] ?? '');
        $competitionName = $this->sanitizeName($competition['name'] ?? null) ?? 'ETF2L';

        $teams = [
            $this->team($match['clan1'] ?? null),
            $this->team($match['clan2'] ?? null),
        ];

        $maps = [];
        foreach (is_array($match['maps'] ?? null) ? $match['maps'] : [] as $map) {
            $name = strtolower(trim((string) $map));
            if ($name !== '' && preg_match('/^[a-z0-9_]+$/', $name) === 1) {
                $maps[] = $name;
            }
        }

        $result = [
            'id' => $matchId,
            'url' => 'https://etf2l.org/matches/'.$matchId.'/',
            'competition' => ['name' => $competitionName, 'type' => $type],
            'category' => match ($type) {
                'Highlander' => '9v9',
                '6v6' => '6v6',
                default => null,
            },
            'teams' => $teams,
            'maps' => $maps,
        ];
        $result['title'] = $this->title($result);

        return $result;
    }

    /**
     * Match normalisé enrichi des rosters de ses équipes connues : chaque
     * équipe porte en plus « players » (SteamID64, pour le remplissage des
     * rosters de la série) et « names » (pseudo ETF2L par SteamID64,
     * informatif). Les équipes inconnues (TBD) ou dont le roster est
     * indisponible restent sans roster — la saisie manuelle prend le relais.
     *
     * @return array<string, mixed>|null
     */
    public function matchWithRosters(int $matchId): ?array
    {
        $match = $this->match($matchId);
        if ($match === null) {
            return null;
        }

        foreach ($match['teams'] as $i => $team) {
            if ($team === null) {
                continue;
            }

            try {
                $roster = $this->teams->roster((int) $team['id']);
            } catch (\Throwable) {
                $roster = null;
            }

            $match['teams'][$i] = array_merge($team, [
                'players' => $roster['players'] ?? [],
                'names' => $roster['names'] ?? [],
            ]);
        }

        return $match;
    }

    /**
     * Titre de série généré depuis un match normalisé : « Compétition —
     * A vs B » avec l'acronyme (tag) de préférence au nom complet, repli
     * « TBD » pour une équipe encore inconnue. Le titre ne s'affiche pas
     * sur l'overlay : c'est un repère dans l'admin.
     *
     * @param  array<string, mixed>  $match  Match normalisé (voir match())
     */
    public function title(array $match): string
    {
        $names = [];
        foreach ($match['teams'] as $team) {
            if (is_array($team)) {
                $names[] = $team['tag'] !== '' ? $team['tag'] : $team['name'];
            } else {
                $names[] = 'TBD';
            }
        }

        if ($names === []) {
            return 'Match ETF2L #'.(int) $match['id'];
        }

        return (string) $match['competition']['name'].' — '.implode(' vs ', $names);
    }

    /**
     * Normalise une équipe du match (clan1 / clan2) : null si le bloc est
     * absent ou sans identifiant exploitable (TBD en playoffs), sinon
     * identifiant, nom complet, tag (acronyme ETF2L, vide si absent) et
     * avatar Steam de l'équipe.
     *
     * @return array<string, mixed>|null
     */
    private function team(mixed $clan): ?array
    {
        if (! is_array($clan)) {
            return null;
        }

        $id = (int) ($clan['id'] ?? 0);
        $name = $this->sanitizeName($clan['name'] ?? null);
        if ($id <= 0 || $name === null) {
            return null;
        }

        $steam = is_array($clan['steam'] ?? null) ? $clan['steam'] : [];

        return [
            'id' => $id,
            'name' => $name,
            'tag' => $this->sanitizeName($clan['tag'] ?? null) ?? '',
            'avatar' => $this->cleanUrl($steam['avatar'] ?? null),
        ];
    }

    /**
     * Appel API avec lecture du cache etf2l_api_cache, écriture après
     * appel (même modèle qu'Etf2lTeamService, TTL court : un match de
     * playoffs révèle ses équipes et ses maps en cours de route).
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
     * réponse de throttle ou d'erreur (status.code 429, 5xx…) ne doit
     * jamais finir en cache. Un payload sans bloc status (fixtures de
     * test) est accepté tel quel.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isValidPayload(array $payload): bool
    {
        $code = $payload['status']['code'] ?? null;

        return $code === null || (int) $code === 200;
    }

    /**
     * Assainit un nom ou un tag : caractères de contrôle supprimés,
     * espaces compressés, taille plafonnée sans couper un caractère UTF-8.
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
