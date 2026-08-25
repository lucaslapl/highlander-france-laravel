<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etf2lRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\LiveMatches;
use App\Services\SteamId;
use App\Services\TwitchLive;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Simulateur d'API du panel admin : injection de données de test dans les
 * caches consommés par le site (matchs en direct, streams Twitch) et
 * manipulation de matchs ETF2L factices, sans serveur TF2 ni stream réel.
 *
 * Les actions contournent volontairement les webhooks publics (token/IP) :
 * elles appellent directement les services, l'objectif étant de tester les
 * affichages. Le payload JSON équivalent au POST SourceMod est renvoyé pour
 * permettre un test manuel complet via curl.
 */
final class AdminApiTestController extends Controller
{
    /** Plage d'IDs réservée aux matchs ETF2L factices (jamais atteinte par l'API réelle). */
    private const FAKE_MATCH_ID_MIN = 900000000;

    private const FAKE_MATCH_ID_MAX = 999999999;

    private const CLASSES_9V9 = ['scout', 'scout', 'soldier', 'soldier', 'demoman', 'medic', 'heavy', 'engineer', 'sniper'];

    /**
     * GET /admin/api-test — page du simulateur.
     */
    public function page(): View
    {
        Auth::requireAdmin();

        $repo = new Etf2lRepository;

        $dbPlayers = DB::table('players_info')
            ->select('steamid', 'name', 'display_name')
            ->orderBy('name')
            ->limit(40)
            ->get()
            ->map(static fn ($row): array => [
                'steamid' => (string) $row->steamid,
                'name' => $row->display_name !== null && $row->display_name !== '' ? (string) $row->display_name : (string) $row->name,
            ])
            ->all();

        return view('admin.api_test', [
            'title' => 'Admin - Simulateur d\'API',
            'description' => 'Simulation des webhooks serveur et manipulation des données ETF2L/Twitch.',
            'liveServers' => LiveMatches::all(),
            'twitchStatus' => TwitchLive::status(),
            'twitchChannels' => array_values((array) config('hlfr.twitch_channels')),
            'etf2lUpcoming' => $repo->upcomingMatches(15),
            'etf2lRecent' => $repo->recentlyFinishedMatches(72, 10),
            'dbPlayers' => $dbPlayers,
        ]);
    }

    /**
     * POST /admin/api-test/live/start — injecte (ou met à jour) un match live.
     */
    public function liveStart(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        [$server, $payload] = $this->buildLivePayload($request);
        if ($server === null || $payload === null) {
            return response()->json(['success' => false, 'message' => 'Nom de serveur requis (1-64 caractères).'], 400);
        }

        if (! LiveMatches::apply($server, 'live', $payload)) {
            return response()->json(['success' => false, 'message' => 'Écriture du cache refusée.'], 500);
        }

        AdminLogger::log('api_test_live_start', null, 'SUCCESS ('.$server.')');

        return response()->json([
            'success' => true,
            'message' => 'Match live injecté sur "'.$server.'".',
            'payload' => $this->webhookExample($server, $payload),
            'state' => LiveMatches::get($server),
        ]);
    }

    /**
     * POST /admin/api-test/live/heartbeat — rafraîchit updated_at d'un match live
     * (contre le TTL de 120 s) en conservant ses données actuelles.
     */
    public function liveHeartbeat(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        $server = trim((string) $request->input('server', ''));

        if ($server === '') {
            return response()->json(['success' => false, 'message' => 'Nom de serveur requis.'], 400);
        }

        $current = LiveMatches::get($server);

        if ($current === null) {
            return response()->json(['success' => false, 'message' => 'Aucun match live sur ce serveur (entrée périmée ou absente).'], 404);
        }

        $current['updated_at'] = time();

        if (! LiveMatches::apply($server, 'live', $current)) {
            return response()->json(['success' => false, 'message' => 'Écriture du cache refusée.'], 500);
        }

        AdminLogger::log('api_test_live_heartbeat', null, 'SUCCESS ('.$server.')');

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat envoyé pour "'.$server.'".',
            'state' => LiveMatches::get($server),
        ]);
    }

    /**
     * POST /admin/api-test/live/end — termine le match d'un serveur.
     */
    public function liveEnd(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        $server = trim((string) $request->input('server', ''));

        if ($server === '') {
            return response()->json(['success' => false, 'message' => 'Nom de serveur requis.'], 400);
        }

        LiveMatches::apply($server, 'ended', []);
        AdminLogger::log('api_test_live_end', null, 'SUCCESS ('.$server.')');

        return response()->json([
            'success' => true,
            'message' => 'Match terminé sur "'.$server.'" (retiré du cache).',
            'state' => LiveMatches::get($server),
        ]);
    }

    /**
     * POST /admin/api-test/live/purge — vide entièrement live_matches.json.
     */
    public function livePurge(): JsonResponse
    {
        Auth::requireAdmin();

        $file = hlfr_data_path(LiveMatches::FILE);

        if (@file_put_contents($file, json_encode(['servers' => [], 'last_updated' => []]), LOCK_EX) === false) {
            return response()->json(['success' => false, 'message' => 'Impossible de vider le fichier '.LiveMatches::FILE.'.'], 500);
        }

        AdminLogger::log('api_test_live_purge');

        return response()->json(['success' => true, 'message' => 'Cache des matchs en direct purgé.', 'state' => []]);
    }

    /**
     * POST /admin/api-test/etf2l — crée ou met à jour un match ETF2L factice.
     * Body : { match_id?, team1_name, team2_name, date_offset_min, competition?, maps? }
     */
    public function etf2lUpsert(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        $matchId = (int) $request->input('match_id', 0);

        if ($matchId === 0) {
            $matchId = self::FAKE_MATCH_ID_MIN + random_int(0, self::FAKE_MATCH_ID_MAX - self::FAKE_MATCH_ID_MIN);
        }

        if ($matchId < self::FAKE_MATCH_ID_MIN || $matchId > self::FAKE_MATCH_ID_MAX) {
            return response()->json([
                'success' => false,
                'message' => 'L\'ID doit être vide (aléatoire) ou compris entre '.self::FAKE_MATCH_ID_MIN.' et '.self::FAKE_MATCH_ID_MAX.'.',
            ], 400);
        }

        $team1 = mb_substr(trim((string) $request->input('team1_name', '')), 0, 128);
        $team2 = mb_substr(trim((string) $request->input('team2_name', '')), 0, 128);

        if ($team1 === '' || $team2 === '') {
            return response()->json(['success' => false, 'message' => 'Les deux noms d\'équipes sont requis.'], 400);
        }

        $offset = (int) $request->input('date_offset_min', 0);
        $maps = trim((string) $request->input('maps', ''));

        $row = [
            'team1_name' => $team1,
            'team2_name' => $team2,
            'match_date' => time() + $offset * 60,
            'competition_name' => mb_substr(trim((string) $request->input('competition', '')), 0, 128) ?: 'Simulateur HLFR',
            'team1_country' => 'france',
            'team2_country' => 'france',
            'maps' => $maps !== '' ? json_encode(array_values(array_map('trim', explode(',', $maps))), JSON_UNESCAPED_SLASHES) : null,
            'r1' => null,
            'r2' => null,
            'map_results' => null,
        ];

        $exists = DB::table('etf2l_matches')->where('match_id', $matchId)->exists();

        if ($exists) {
            DB::table('etf2l_matches')->where('match_id', $matchId)->update($row);
        } else {
            $row['match_id'] = $matchId;
            DB::table('etf2l_matches')->insert($row);
        }

        AdminLogger::log('api_test_etf2l_upsert', null, ($exists ? 'UPDATE #' : 'CREATE #').$matchId);

        return response()->json([
            'success' => true,
            'message' => 'Match ETF2L factice '.($exists ? 'mis à jour' : 'créé').' #'.$matchId.' ('.e($team1).' vs '.e($team2).').',
            'match_url' => '/match/'.$matchId,
        ]);
    }

    /**
     * POST /admin/api-test/etf2l/delete — supprime un match factice.
     */
    public function etf2lDelete(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        $matchId = (int) $request->input('match_id', 0);

        if ($matchId < self::FAKE_MATCH_ID_MIN || $matchId > self::FAKE_MATCH_ID_MAX) {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les matchs factices (ID ≥ '.self::FAKE_MATCH_ID_MIN.') peuvent être supprimés ici.',
            ], 400);
        }

        $deleted = DB::table('etf2l_matches')->where('match_id', $matchId)->delete() > 0;

        AdminLogger::log('api_test_etf2l_delete', null, ($deleted ? 'SUCCESS #' : 'NOT FOUND #').$matchId);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'Match factice #'.$matchId.' supprimé.' : 'Match #'.$matchId.' introuvable.',
        ]);
    }

    /**
     * POST /admin/api-test/twitch — simule une chaîne Twitch en direct.
     * Body : { login, title, viewers, auto_match: bool, replace_all: bool, match_ids?: [] }
     */
    public function twitchSimulate(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        $login = mb_strtolower(mb_substr(trim((string) $request->input('login', '')), 0, 64));

        if (! preg_match('/^[a-z0-9_]{3,64}$/', $login)) {
            return response()->json(['success' => false, 'message' => 'Login Twitch invalide (3-64 caractères alphanumériques/_).'], 400);
        }

        $title = mb_substr(trim((string) $request->input('title', '')), 0, 200);
        $viewers = max(0, min(1000000, (int) $request->input('viewers', 42)));

        if ((bool) $request->boolean('auto_match')) {
            $matched = $this->computeMatchedIds($title);
        } else {
            $matched = array_map(static fn ($id): int => (int) $id, (array) $request->input('match_ids', []));
        }

        $channel = [
            'login' => $login,
            'display_name' => $login,
            'title' => $title,
            'viewers' => $viewers,
            'game_name' => 'Team Fortress 2',
            'started_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 600),
            'url' => 'https://www.twitch.tv/'.$login,
            'matched_match_ids' => $matched,
        ];

        $cacheFile = hlfr_data_path(TwitchLive::FILE);
        $data = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        $data = is_array($data) ? $data : [];

        // Remplace toute entrée existante du même login, conserve les autres
        // (sauf replace_all : on vide le cache pour un test isolé).
        if ((bool) $request->boolean('replace_all')) {
            $channels = [];
        } else {
            $channels = array_values(array_filter(
                is_array($data['channels'] ?? null) ? $data['channels'] : [],
                static fn ($c): bool => ! is_array($c) || (($c['login'] ?? '') !== $login)
            ));
        }
        $channels[] = $channel;

        $data['channels'] = $channels;
        $data['fetched_at'] = time();
        unset($data['stale']);

        if (@file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            return response()->json(['success' => false, 'message' => 'Impossible d\'écrire '.TwitchLive::FILE.'.'], 500);
        }

        AdminLogger::log('api_test_twitch_simulate', null, 'SUCCESS ('.$login.', '.count($matched).' match(s) associé(s))');

        return response()->json([
            'success' => true,
            'message' => 'Stream simulé : "'.$login.'" en direct ('.count($matched).' match(s) associé(s)).',
            'state' => TwitchLive::status(),
        ]);
    }

    /**
     * POST /admin/api-test/twitch/reset — vide le cache des streams Twitch.
     */
    public function twitchReset(): JsonResponse
    {
        Auth::requireAdmin();

        $file = hlfr_data_path(TwitchLive::FILE);

        if (@file_put_contents($file, json_encode(['fetched_at' => time(), 'channels' => []]), LOCK_EX) === false) {
            return response()->json(['success' => false, 'message' => 'Impossible de réinitialiser '.TwitchLive::FILE.'.'], 500);
        }

        AdminLogger::log('api_test_twitch_reset');

        return response()->json(['success' => true, 'message' => 'Cache Twitch réinitialisé (plus aucun stream en direct).', 'state' => TwitchLive::status()]);
    }

    // --- Helpers privés ---

    /**
     * Construit le payload d'état live à partir du formulaire.
     *
     * @return array{0: ?string, 1: ?array<string, mixed>} [serveur, payload]
     */
    private function buildLivePayload(Request $request): array
    {
        $server = mb_substr(trim((string) $request->input('server', '')), 0, 64);

        if ($server === '') {
            return [null, null];
        }

        $minutesElapsed = max(0, min(180, (int) $request->input('minutes_elapsed', 15)));
        $now = time();

        return [$server, [
            'map' => mb_substr(trim((string) $request->input('map', '')), 0, 64),
            'scores' => [
                'red' => max(0, (int) $request->input('score_red', 0)),
                'blue' => max(0, (int) $request->input('score_blue', 0)),
            ],
            'started_at' => $now - $minutesElapsed * 60,
            'updated_at' => $now,
            'stv' => trim((string) $request->input('stv', '')) !== '' ? ['connect' => mb_substr(trim((string) $request->input('stv')), 0, 512)] : null,
            'players' => $this->buildPlayers($request),
        ]];
    }

    /**
     * Joueurs selon la source choisie : générés (9v9), tirés de la base, ou aucun.
     *
     * @return array<int, array<string, string|int>>
     */
    private function buildPlayers(Request $request): array
    {
        $source = (string) $request->input('players_source', 'auto');

        if ($source === 'none') {
            return [];
        }

        if ($source === 'db') {
            $steamids = array_slice((array) $request->input('steamids', []), 0, 18);
            $names = array_column(DB::table('players_info')
                ->whereIn('steamid', array_map([SteamId::class, 'toSteamId3'], $steamids))
                ->get(['steamid', 'name'])
                ->all(), 'name', 'steamid');

            $players = [];
            foreach ($steamids as $i => $steamid64) {
                $steamid3 = SteamId::toSteamId3((string) $steamid64);
                $players[] = [
                    'name' => mb_substr((string) ($names[$steamid3] ?? 'Joueur '.($i + 1)), 0, 64),
                    'team' => $i < 9 ? 'red' : 'blue',
                    'class' => self::CLASSES_9V9[$i % 9],
                    'steamid' => $this->toSteam2((string) $steamid64) ?? 'STEAM_1:0:'.(100000 + $i),
                    'score' => random_int(1, 25),
                ];
            }

            return $players;
        }

        // Génération auto : 18 joueurs fictifs (9 vs 9) avec classes 9v9.
        $players = [];

        foreach (['red', 'blue'] as $side) {
            for ($i = 0; $i < 9; $i++) {
                $players[] = [
                    'name' => ucfirst($side).' Joueur '.($i + 1),
                    'team' => $side,
                    'class' => self::CLASSES_9V9[$i],
                    'steamid' => 'STEAM_1:'.($i % 2).':'.random_int(20000000, 99999999),
                    'score' => random_int(1, 25),
                ];
            }
        }

        return $players;
    }

    /**
     * Exemple de requête webhook équivalente (POST /api/server/live-status).
     *
     * @param  array<string, mixed>  $payload
     */
    private function webhookExample(string $server, array $payload): string
    {
        $body = $payload;
        $body['server'] = $server;
        $body['token'] = '***SERVER_WEBHOOK_TOKEN***';

        return 'curl -X POST '.url('/api/server/live-status')." \\\n"
            ."  -H \"Content-Type: application/json\" \\\n"
            ."  -d '".json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."'";
    }

    /**
     * Association titre de stream <-> matchs ETF2L (fenêtre ±4 h, scores absents),
     * même logique que TwitchLive::matchStreams : forte si les deux équipes sont
     * dans le titre, faible unique sinon.
     *
     * @return array<int, int>
     */
    private function computeMatchedIds(string $title): array
    {
        $title = self::normalizeName($title);

        if ($title === '') {
            return [];
        }

        $now = time();
        $rows = DB::table('etf2l_matches')
            ->whereBetween('match_date', [$now - 4 * 3600, $now + 4 * 3600])
            ->whereNull('r1')
            ->get(['match_id', 'team1_name', 'team2_name']);

        $strong = [];
        $weak = [];

        foreach ($rows as $row) {
            $t1 = self::normalizeName((string) ($row->team1_name ?? ''));
            $t2 = self::normalizeName((string) ($row->team2_name ?? ''));

            if ($t1 === '' || $t2 === '' || $t1 === $t2) {
                continue;
            }

            $has1 = str_contains($title, $t1);
            $has2 = str_contains($title, $t2);

            if ($has1 && $has2) {
                $strong[] = (int) $row->match_id;
            } elseif ($has1 || $has2) {
                $weak[] = (int) $row->match_id;
            }
        }

        if ($strong !== []) {
            return $strong;
        }

        return count($weak) === 1 ? $weak : [];
    }

    /**
     * Normalisation identique à TwitchLive::normalize().
     */
    private static function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($translit !== false) {
            $value = $translit;
        }

        return (string) preg_replace('/[^a-z0-9]/', '', $value);
    }

    /**
     * Convertit un SteamID64 en format STEAM_2 (celui envoyé par SourceMod).
     */
    private function toSteam2(string $steamid64): ?string
    {
        if (! preg_match('/^\d{17}$/', $steamid64)) {
            return null;
        }

        $account = (int) bcsub($steamid64, '76561197960265728');
        $y = $account % 2;

        return 'STEAM_1:'.$y.':'.intdiv($account - $y, 2);
    }
}
