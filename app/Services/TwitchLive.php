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

    /** Nombre maximum de streamers affichés dans l'encadré sidebar. */
    private const SIDEBAR_MAX = 5;

    private const OAUTH_URL = 'https://id.twitch.tv/oauth2/token';

    private const STREAMS_URL = 'https://api.twitch.tv/helix/streams';

    private const VIDEOS_URL = 'https://api.twitch.tv/helix/videos';

    private const USERS_URL = 'https://api.twitch.tv/helix/users';

    /** Chaîne affichée dans le lecteur intégré de l'accueil (login minuscule). */
    private const EMBED_CHANNEL = 'highlanderfrance';

    /**
     * État servi à l'API : chaînes en direct (+ matchs associés par titre),
     * état du lecteur intégré de l'accueil (live ou dernière VOD) et liste de
     * l'encadré sidebar (streamers FR TF2, HL France mis en avant).
     *
     * @return array{channels: array<int, array<string, mixed>>, stale: bool, sidebar: array<int, array<string, mixed>>, embed: array<string, mixed>|null}
     */
    public static function status(): array
    {
        $data = self::read();
        $channels = is_array($data['channels'] ?? null) ? array_values($data['channels']) : [];
        $sidebar = is_array($data['sidebar'] ?? null) ? array_values($data['sidebar']) : [];

        if ($channels !== [] && (! isset($data['fetched_at']) || (int) $data['fetched_at'] < time() - self::STALE_MAX)) {
            // Cache trop ancien : on préfère masquer les badges plutôt que
            // d'afficher un direct probablement terminé. Le lecteur garde le
            // dernier état connu (l'embed canal se corrige de lui-même).
            return ['channels' => [], 'stale' => true, 'sidebar' => [], 'embed' => is_array($data['embed'] ?? null) ? $data['embed'] : null];
        }

        return [
            'channels' => $channels,
            'stale' => false,
            'sidebar' => $sidebar,
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

        $live = self::normalizeLive($streams, $logins);

        self::matchStreams($live);

        // Encadré sidebar : streams FR TF2 (échec de collecte non bloquant) et
        // chaînes « HL France » de la liste dédiée (twitch_hl_channels), sondées
        // en plus sans toucher aux badges ni au lecteur embed.
        $french = self::fetchFrenchStreams($token);
        $hlLive = self::fetchHlStreams($token);

        // Conserve les chaînes simulées par le simulateur admin à travers les
        // rafraîchissements réels : le cron n'écrase que les données Helix,
        // les entrées simulées restent jusqu'au reset manuel du cache.
        $previousChannels = is_array($cache['channels'] ?? null) ? $cache['channels'] : [];

        $cache['fetched_at'] = time();
        $cache['channels'] = self::mergeSimulated($previousChannels, $live);
        $cache['sidebar'] = self::buildSidebar(self::mergeSimulated($previousChannels, $hlLive), $french);
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
     * Normalise les flux Helix bruts en entrées de cache, en ne gardant que
     * les logins réellement suivis (garde-fou anti-réponse inattendue).
     *
     * @param  array<int, mixed>  $streams  Flux bruts de /helix/streams.
     * @param  array<int, string>  $logins  Logins autorisés.
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeLive(array $streams, array $logins): array
    {
        $live = [];

        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $login = mb_strtolower((string) ($stream['user_login'] ?? ''));

            if ($login === '' || ! in_array($login, $logins, true)) {
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

        return $live;
    }

    /**
     * Chaînes « HL France » (liste dédiée twitch_hl_channels) actuellement en
     * direct, pour la mise en avant de l'encadré sidebar. Un échec (HTTP !=
     * 200) renvoie une liste vide, sans casser le reste du refresh.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetchHlStreams(string $token): array
    {
        $hlLogins = array_values((array) config('hlfr.twitch_hl_channels'));

        if ($hlLogins === []) {
            return [];
        }

        [$streams, $httpCode] = self::fetchStreams($hlLogins, $token);

        if ($httpCode !== 200) {
            return [];
        }

        return self::normalizeLive($streams, $hlLogins);
    }

    /**
     * Chaînes francophones actuellement en direct sur le jeu cible (TF2) pour
     * l'encadré sidebar : /helix/streams avec les filtres game_id + language.
     * Un échec (HTTP != 200) renvoie une liste vide : la partie HL France
     * reste exploitable.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetchFrenchStreams(string $token): array
    {
        $gameId = trim((string) config('hlfr.twitch_tf2_game_id'));
        $language = trim((string) config('hlfr.twitch_fr_language'));

        if ($gameId === '' || $language === '') {
            return [];
        }

        $url = self::STREAMS_URL.'?game_id='.rawurlencode($gameId)
            .'&language='.rawurlencode($language).'&first=100';

        $headers = [
            'Client-Id: '.(string) config('hlfr.twitch_client_id'),
            'Authorization: Bearer '.$token,
        ];

        $meta = JsonClient::getWithMeta($url, 10, 'Highlander France Bot/1.0', $headers);
        $streams = $meta['data']['data'] ?? [];

        if ($meta['http_code'] !== 200 || ! is_array($streams)) {
            return [];
        }

        $items = [];
        foreach ($streams as $stream) {
            $login = mb_strtolower((string) ($stream['user_login'] ?? ''));

            if ($login === '') {
                continue;
            }

            $items[] = [
                'login' => $login,
                'display_name' => (string) ($stream['user_name'] ?? $stream['user_login'] ?? ''),
                'title' => (string) ($stream['title'] ?? ''),
                'viewers' => max(0, (int) ($stream['viewer_count'] ?? 0)),
                'game_name' => (string) ($stream['game_name'] ?? ''),
                'url' => 'https://www.twitch.tv/'.$login,
            ];
        }

        return $items;
    }

    /**
     * Liste de l'encadré sidebar : les chaînes HL France (liste dédiée
     * `twitch_hl_channels`, simulées comprises) sont mises en avant (hl=true),
     * puis les autres streams FR TF2 (hl=false). Tri par viewers décroissant
     * dans chaque groupe, dédoublonnage par login (HL France prioritaire) et
     * plafond appliqué à l'ensemble.
     *
     * @param  array<int, array<string, mixed>>  $priority  Chaînes HL France (cache channels, filtrées twitch_hl_channels + simulées).
     * @param  array<int, array<string, mixed>>  $french  Streams FR TF2 (Helix).
     * @return array<int, array<string, mixed>>
     */
    public static function buildSidebar(array $priority, array $french, int $max = self::SIDEBAR_MAX): array
    {
        $toItem = static function (array $channel, bool $hl): ?array {
            $login = mb_strtolower((string) ($channel['login'] ?? ''));

            if ($login === '') {
                return null;
            }

            return [
                'login' => $login,
                'display_name' => (string) ($channel['display_name'] ?? $login),
                'title' => (string) ($channel['title'] ?? ''),
                'viewers' => max(0, (int) ($channel['viewers'] ?? 0)),
                'url' => (string) ($channel['url'] ?? 'https://www.twitch.tv/'.$login),
                'game_name' => (string) ($channel['game_name'] ?? ''),
                'hl' => $hl,
            ];
        };

        $sortByViewersDesc = static function (array &$group): void {
            usort($group, static fn (array $a, array $b): int => ($b['viewers'] ?? 0) <=> ($a['viewers'] ?? 0));
        };

        $priorityItems = [];
        foreach ($priority as $channel) {
            if (is_array($channel)) {
                $item = $toItem($channel, true);
                if ($item !== null) {
                    $priorityItems[] = $item;
                }
            }
        }
        $sortByViewersDesc($priorityItems);

        $seen = [];
        foreach ($priorityItems as $item) {
            $seen[$item['login']] = true;
        }

        $otherItems = [];
        foreach ($french as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $item = $toItem($channel, false);
            if ($item === null || isset($seen[$item['login']])) {
                continue;
            }
            $seen[$item['login']] = true;
            $otherItems[] = $item;
        }
        $sortByViewersDesc($otherItems);

        return array_slice(array_merge($priorityItems, $otherItems), 0, max(0, $max));
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
     * Reconstruit la liste des chaînes à servir : les chaînes réellement en
     * direct (Helix) plus les entrées marquées `simulated` par le simulateur
     * admin encore présentes dans le cache précédent. Une chaîne réelle prime
     * sur une simulée de même login (celle-ci est alors abandonnée).
     *
     * @param  array<int, array<string, mixed>>  $previous  Chaînes du cache précédent.
     * @param  array<int, array<string, mixed>>  $live  Chaînes actuellement en direct (Helix).
     * @return array<int, array<string, mixed>>
     */
    public static function mergeSimulated(array $previous, array $live): array
    {
        $simulated = array_values(array_filter(
            $previous,
            static fn (mixed $ch): bool => is_array($ch) && ($ch['simulated'] ?? false) === true
        ));

        if ($simulated === []) {
            return $live;
        }

        $liveLogins = [];
        foreach ($live as $channel) {
            $login = (string) ($channel['login'] ?? '');
            if ($login !== '') {
                $liveLogins[$login] = true;
            }
        }

        foreach ($simulated as $sim) {
            $login = (string) ($sim['login'] ?? '');

            if ($login !== '' && isset($liveLogins[$login])) {
                continue;
            }

            $live[] = $sim;
        }

        return $live;
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
