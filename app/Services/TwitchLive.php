<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Détection des chaînes Twitch suivies actuellement en direct.
 *
 * Le cache est rafraîchi par la commande planifiée app:sync-twitch (verrou
 * flock anti-concurrence) ; l'endpoint /api/twitch-live ne fait que le lire,
 * si bien qu'aucune requête utilisateur ne déclenche d'appel HTTP externe.
 */
final class TwitchLive
{
    public const FILE = 'cache_twitch_live.json';

    /** Verrou anti-concurrence : un seul refresh à la fois (cron + run manuel). */
    private const LOCK_FILE = 'twitch_live.lock';

    /** Au-delà de ce délai sans rafraîchissement réussi, la liste servie est vide. */
    private const STALE_MAX = 900;

    /** Marge avant expiration pour renouveler le token applicatif OAuth. */
    private const TOKEN_REFRESH_MARGIN = 3600;

    /** Fenêtre horaire d'association stream <-> match (±4 h autour de maintenant). */
    private const TIME_WINDOW = 4 * 3600;

    private const OAUTH_URL = 'https://id.twitch.tv/oauth2/token';

    private const STREAMS_URL = 'https://api.twitch.tv/helix/streams';

    private const VIDEOS_URL = 'https://api.twitch.tv/helix/videos';

    private const USERS_URL = 'https://api.twitch.tv/helix/users';

    /** Chaîne affichée dans le lecteur intégré de l'accueil (login minuscule). */
    private const EMBED_CHANNEL = 'highlanderfrance';

    /**
     * État servi à l'API : chaînes en direct (+ matchs associés par titre)
     * et état du lecteur intégré de l'accueil (live ou dernière VOD).
     *
     * @return array{channels: array<int, array<string, mixed>>, stale: bool, embed: array<string, mixed>|null}
     */
    public static function status(): array
    {
        $data = self::read();
        $channels = is_array($data['channels'] ?? null) ? array_values($data['channels']) : [];

        if ($channels !== [] && (! isset($data['fetched_at']) || (int) $data['fetched_at'] < time() - self::STALE_MAX)) {
            // Cache trop ancien : on préfère masquer les badges plutôt que
            // d'afficher un direct probablement terminé. Le lecteur garde le
            // dernier état connu (l'embed canal se corrige de lui-même).
            return ['channels' => [], 'stale' => true, 'embed' => is_array($data['embed'] ?? null) ? $data['embed'] : null];
        }

        return [
            'channels' => $channels,
            'stale' => false,
            'embed' => is_array($data['embed'] ?? null) ? $data['embed'] : null,
        ];
    }

    /**
     * Rafraîchit le cache depuis l'API Helix (appelé par app:sync-twitch).
     */
    public static function refresh(): string
    {
        if (config('hlfr.twitch_client_id') === '' || config('hlfr.twitch_client_secret') === '') {
            return 'Streams Twitch désactivés : TWITCH_CLIENT_ID / TWITCH_CLIENT_SECRET non renseignés.';
        }

        $logins = array_values((array) config('hlfr.twitch_channels'));

        if ($logins === []) {
            return 'Streams Twitch désactivés : TWITCH_CHANNELS ne contient aucune chaîne.';
        }

        // Une seule exécution à la fois : deux crons qui se chevauchent
        // consommeraient le quota Helix pour rien.
        $lock = fopen(hlfr_data_path(self::LOCK_FILE), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return 'Rafraîchissement Twitch ignoré : une autre exécution est déjà en cours.';
        }

        try {
            $count = self::doRefresh($logins);

            return 'SUCCESS ('.$count.' chaîne(s) en direct)';
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function doRefresh(array $logins): int
    {
        $cache = self::read();

        $token = self::ensureToken($cache);
        [$streams, $httpCode] = self::fetchStreams($logins, $token);

        // Token invalidé côté Twitch (révocation, rotation du secret) : on le
        // renouvelle une fois avant d'abandonner.
        if ($httpCode === 401) {
            unset($cache['token']);
            $token = self::ensureToken($cache);
            [$streams, $httpCode] = self::fetchStreams($logins, $token);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException('Appel API Twitch impossible (HTTP '.$httpCode.')');
        }

        $live = [];
        foreach ($streams as $stream) {
            $login = mb_strtolower((string) ($stream['user_login'] ?? ''));

            // Garde-fou : ne servir que les logins réellement suivis.
            if (! in_array($login, $logins, true)) {
                continue;
            }

            $live[] = [
                'login' => $login,
                'display_name' => (string) ($stream['user_name'] ?? $stream['user_login'] ?? ''),
                'title' => (string) ($stream['title'] ?? ''),
                'viewers' => max(0, (int) ($stream['viewer_count'] ?? 0)),
                'game_name' => (string) ($stream['game_name'] ?? ''),
                'started_at' => (string) ($stream['started_at'] ?? ''),
                'url' => 'https://www.twitch.tv/'.$login,
                'matched_match_ids' => [],
            ];
        }

        self::matchStreams($live);

        $cache['fetched_at'] = time();
        $cache['channels'] = $live;
        $cache['embed'] = in_array(self::EMBED_CHANNEL, $logins, true)
            ? self::resolveEmbed($live, $token, $cache)
            : null;

        self::write($cache);

        return count($live);
    }

    /**
     * Retourne un token applicatif valide, en le renouvelant dans le cache si
     * nécessaire. Un échec OAuth interrompt le refresh (cache conservé).
     *
     * @param  array<string, mixed>  $cache  Référence : le token y est mémorisé.
     * @return string Access token.
     */
    private static function ensureToken(array &$cache): string
    {
        $token = is_array($cache['token'] ?? null) ? $cache['token'] : null;

        if (
            is_string($token['access_token'] ?? null) && $token['access_token'] !== ''
            && (int) ($token['expires_at'] ?? 0) > time() + self::TOKEN_REFRESH_MARGIN
        ) {
            return $token['access_token'];
        }

        $meta = JsonClient::postForm(self::OAUTH_URL, [
            'client_id' => (string) config('hlfr.twitch_client_id'),
            'client_secret' => (string) config('hlfr.twitch_client_secret'),
            'grant_type' => 'client_credentials',
        ]);

        $payload = $meta['data'];
        $accessToken = is_array($payload) ? (string) ($payload['access_token'] ?? '') : '';

        if ($meta['curl_error'] !== '' || $accessToken === '' || ! isset($payload['expires_in'])) {
            throw new \RuntimeException(
                "Obtention du token Twitch impossible (HTTP {$meta['http_code']}"
                .($meta['curl_error'] !== '' ? ', cURL : '.$meta['curl_error'] : '').')'
            );
        }

        $cache['token'] = [
            'access_token' => $accessToken,
            'expires_at' => time() + max(60, (int) $payload['expires_in']),
        ];

        return $accessToken;
    }

    /**
     * Interroge /helix/streams pour tous les logins en une seule requête
     * (les chaînes hors-ligne sont simplement absentes de la réponse).
     *
     * @return array{0: array<int, mixed>, 1: int} Flux bruts + code HTTP.
     */
    private static function fetchStreams(array $logins, string $token): array
    {
        $url = self::STREAMS_URL.'?'.implode('&', array_map(
            static fn (string $login): string => 'user_login='.rawurlencode($login),
            $logins
        ));

        $headers = [
            'Client-Id: '.(string) config('hlfr.twitch_client_id'),
            'Authorization: Bearer '.$token,
        ];

        $meta = JsonClient::getWithMeta($url, 10, 'Highlander France Bot/1.0', $headers);
        $streams = $meta['data']['data'] ?? [];

        return [is_array($streams) ? $streams : [], $meta['http_code']];
    }

    /**
     * Associe chaque flux live aux matchs ETF2L candidats via le titre :
     * association forte si les deux équipes figurent dans le titre, sinon
     * association faible acceptée uniquement si elle est non ambiguë.
     *
     * @param  array<int, array<string, mixed>>  $live  Référence : remplit matched_match_ids.
     */
    private static function matchStreams(array &$live): void
    {
        if ($live === []) {
            return;
        }

        $now = time();
        $rows = DB::table('etf2l_matches')
            ->whereBetween('match_date', [$now - self::TIME_WINDOW, $now + self::TIME_WINDOW])
            ->whereNull('r1')
            ->get(['match_id', 'team1_name', 'team2_name']);

        if ($rows->isEmpty()) {
            return;
        }

        $candidates = [];
        foreach ($rows as $row) {
            $t1 = self::normalize((string) ($row->team1_name ?? ''));
            $t2 = self::normalize((string) ($row->team2_name ?? ''));

            if ($t1 === '' || $t2 === '' || $t1 === $t2) {
                continue;
            }

            $candidates[] = [(int) $row->match_id, $t1, $t2];
        }

        foreach ($live as &$channel) {
            $title = self::normalize((string) ($channel['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $strong = [];
            $weak = [];

            foreach ($candidates as [$matchId, $t1, $t2]) {
                $has1 = str_contains($title, $t1);
                $has2 = str_contains($title, $t2);

                if ($has1 && $has2) {
                    $strong[] = $matchId;
                } elseif ($has1 || $has2) {
                    $weak[] = $matchId;
                }
            }

            if ($strong !== []) {
                $channel['matched_match_ids'] = $strong;
            } elseif (count($weak) === 1) {
                $channel['matched_match_ids'] = $weak;
            }
            // Plusieurs candidats faibles : ambiguïté, aucune association
            // (le JS affichera la bannière générique).
        }
        unset($channel);
    }

    /**
     * État du lecteur intégré de l'accueil : direct si la chaîne de référence
     * est en ligne, sinon la VOD (archive) la plus récente. En cas d'échec de
     * l'appel vidéos, un état sans video_id est renvoyé : le front se replie
     * sur l'embed canal simple.
     *
     * @param  array<int, array<string, mixed>>  $live  Chaînes actuellement en direct.
     * @param  array<string, mixed>  $cache  Référence : mémorise l'user_id Twitch.
     * @return array<string, mixed>
     */
    private static function resolveEmbed(array $live, string $token, array &$cache): array
    {
        foreach ($live as $channel) {
            if (($channel['login'] ?? '') === self::EMBED_CHANNEL) {
                return [
                    'live' => true,
                    'channel' => self::EMBED_CHANNEL,
                    'video_id' => null,
                    'title' => (string) ($channel['title'] ?? ''),
                    'viewers' => max(0, (int) ($channel['viewers'] ?? 0)),
                ];
            }
        }

        $userId = is_string($cache['embed_user_id'] ?? null) ? $cache['embed_user_id'] : '';
        if ($userId === '') {
            $userId = self::resolveUserId($token);
            if ($userId === '') {
                return self::embedFallback();
            }
            // Résolution stable dans le temps : on évite un aller-retour
            // /helix/users à chaque cron tant que la chaîne ne change pas.
            $cache['embed_user_id'] = $userId;
        }

        $headers = [
            'Client-Id: '.(string) config('hlfr.twitch_client_id'),
            'Authorization: Bearer '.$token,
        ];

        // /helix/videos n'accepte pas user_login : user_id résolu ci-dessus.
        $url = self::VIDEOS_URL.'?user_id='.rawurlencode($userId)
            .'&first=1&type=archive&sort=time';

        $meta = JsonClient::getWithMeta($url, 10, 'Highlander France Bot/1.0', $headers);
        $videos = is_array($meta['data']['data'] ?? null) ? $meta['data']['data'] : [];

        if ($meta['http_code'] !== 200 || $videos === []) {
            return self::embedFallback();
        }

        $vod = $videos[0];

        return [
            'live' => false,
            'channel' => self::EMBED_CHANNEL,
            'video_id' => preg_replace('/\D/', '', (string) ($vod['id'] ?? '')) ?: null,
            'title' => (string) ($vod['title'] ?? ''),
            'viewers' => 0,
        ];
    }

    /** État dégradé : pas de VOD connue, le front affichera l'embed canal. */
    private static function embedFallback(): array
    {
        return [
            'live' => false,
            'channel' => self::EMBED_CHANNEL,
            'video_id' => null,
            'title' => '',
            'viewers' => 0,
        ];
    }

    /**
     * Résout l'identifiant numérique Twitch d'un login via /helix/users.
     */
    private static function resolveUserId(string $token): string
    {
        $url = self::USERS_URL.'?login='.rawurlencode(self::EMBED_CHANNEL);
        $headers = [
            'Client-Id: '.(string) config('hlfr.twitch_client_id'),
            'Authorization: Bearer '.$token,
        ];

        $meta = JsonClient::getWithMeta($url, 10, 'Highlander France Bot/1.0', $headers);
        $users = is_array($meta['data']['data'] ?? null) ? $meta['data']['data'] : [];
        $id = preg_replace('/\D/', '', (string) ($users[0]['id'] ?? ''));

        return $id !== '' ? $id : '';
    }

    /**
     * Normalisation pour comparaison de noms/titres : minuscules, suppression
     * des diacritiques puis de tout caractère non alphanumérique.
     */
    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        $value = $translit !== false ? $translit : strtr($value, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
            'ÿ' => 'y',
        ]);

        return (string) preg_replace('/[^a-z0-9]/', '', $value);
    }

    /** @return array<string, mixed> */
    private static function read(): array
    {
        $file = hlfr_data_path(self::FILE);
        if (! is_file($file)) {
            return ['fetched_at' => 0, 'channels' => []];
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            return ['fetched_at' => 0, 'channels' => []];
        }

        return $data;
    }

    private static function write(array $data): bool
    {
        $fp = fopen(hlfr_data_path(self::FILE), 'c');

        if ($fp === false) {
            return false;
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        $written = fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $written !== false;
    }
}
