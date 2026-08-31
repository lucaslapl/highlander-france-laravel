<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Etf2lRepository;
use App\Models\MatchLogRepository;
use App\Models\PlayerRepository;
use App\Services\Auth;
use App\Services\CommunityNews;
use App\Services\CountryFlags;
use App\Services\FranceBadgeService;
use App\Services\LiveMatches;
use App\Services\LogsTfApi;
use App\Services\MatchFormat;
use App\Services\SteamId;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class PageController extends Controller
{
    public function home(): View
    {
        $repo = new Etf2lRepository;
        $prochainsMatchs = $repo->upcomingMatches(5);
        $matchsRecents = $repo->recentlyFinishedMatches(48, 5);

        // Sidebar : actualités communautaires + derniers inscrits.
        // Chaque source est isolée : une panne ne casse jamais la page.
        try {
            $communityNews = (new CommunityNews)->news(5);
        } catch (\Throwable) {
            $communityNews = [];
        }

        $latestPlayers = [];
        try {
            foreach ((new PlayerRepository)->latestRegistered(5) as $row) {
                $steamid64 = SteamId::toSteamId64((string) $row['steamid']);
                if ($steamid64 === null) {
                    continue;
                }
                $hasCountry = ! empty($row['country']) && $row['country'] !== 'unknown';
                $latestPlayers[] = [
                    'name' => ($row['display_name'] ?? '') !== '' ? $row['display_name'] : ($row['name'] ?? 'Joueur'),
                    'avatar' => (string) $row['avatar'],
                    'steamid64' => $steamid64,
                    'profile_url' => '/profile/'.$steamid64,
                    'country' => $hasCountry ? (string) $row['country'] : null,
                    'flag_url' => $hasCountry ? CountryFlags::flag((string) $row['country']) : null,
                ];
            }
        } catch (\Throwable) {
            $latestPlayers = [];
        }

        return view('pages.home', [
            'title' => 'Highlander France - Communauté Compétitive de TF2',
            'description' => site_description(),
            'isHome' => true,
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
            ],
        ] + compact('prochainsMatchs', 'matchsRecents', 'communityNews', 'latestPlayers'));
    }

    /**
     * Détail d'un match ETF2L (GET /match/{id}) avec les rosters des équipes.
     */
    public function etf2lMatch(Request $request): View
    {
        $matchId = (int) $request->route('id', 0);

        if ($matchId <= 0) {
            abort(404);
        }

        $detail = (new Etf2lRepository)->etf2lMatchDetail($matchId);

        if ($detail === null) {
            abort(404);
        }

        $match = $detail['match'];
        $dt = new \DateTime('@'.(int) $match['match_date']);
        $dt->setTimezone(new \DateTimeZone('Europe/Paris'));
        $teamNames = array_filter([
            (string) ($match['team1_name'] ?? ''),
            (string) ($match['team2_name'] ?? ''),
        ]);
        $matchTitle = implode(' VS ', array_values($teamNames));
        $description = 'Match ETF2L '.e((string) ($match['competition_name'] ?? 'Highlander'))
            .' : '.$matchTitle.' ('.$dt->format('d/m/Y à H:i').'). '
            .'Consultez les rosters des deux équipes et les scores des maps.';

        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'SportsEvent',
            'name' => $matchTitle.' - '.($match['competition_name'] ?? 'ETF2L'),
            'description' => $description,
            'startDate' => $dt->format('c'),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
            'url' => site_url().'/match/'.(int) $match['match_id'],
            'sport' => 'Team Fortress 2',
            'location' => [
                '@type' => 'Place',
                'name' => 'ETF2L',
                'url' => 'https://etf2l.org/matches/'.(int) $match['match_id'],
            ],
            'competitor' => array_map(static function (array $team): array {
                return [
                    '@type' => 'SportsTeam',
                    'name' => $team['name'] ?? '',
                    'member' => array_map(
                        static fn (array $p): array => ['@type' => 'Person', 'name' => $p['name'] ?? ''],
                        $team['players'] ?? []
                    ),
                ];
            }, $detail['teams']),
        ];

        return view('pages.etf2l-match', [
            'title' => 'Highlander France - '.$matchTitle.' | ETF2L',
            'description' => $description,
            'structuredData' => $structuredData,
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'Matchs ETF2L', 'url' => site_url().'/matchs'],
                ['name' => $matchTitle, 'url' => site_url().'/match/'.(int) $match['match_id']],
            ],
            'match' => $match,
            'teams' => $detail['teams'],
            'mapsData' => $detail['maps'],
            'result1' => MatchFormat::teamResult(
                isset($detail['maps']['r1']) ? (int) $detail['maps']['r1'] : null,
                isset($detail['maps']['r2']) ? (int) $detail['maps']['r2'] : null
            ),
            'result2' => MatchFormat::teamResult(
                isset($detail['maps']['r2']) ? (int) $detail['maps']['r2'] : null,
                isset($detail['maps']['r1']) ? (int) $detail['maps']['r1'] : null
            ),
            'dateMatch' => $dt->format('d/m/Y'),
            'heureMatch' => $dt->format('H:i'),
        ]);
    }

    public function etf2lMaps(): View
    {
        $maps6v6 = [
            ['name' => 'cp_sunshine', 'label' => 'cp_sunshine', 'file' => 'cp_sunshine.bsp'],
            ['name' => 'cp_process_f12', 'label' => 'cp_process_f12', 'file' => 'cp_process_f12.bsp'],
            ['name' => 'cp_gullywash_f9', 'label' => 'cp_gullywash_f9', 'file' => 'cp_gullywash_f9.bsp'],
            ['name' => 'cp_metalworks_f7', 'label' => 'cp_metalworks_f7', 'file' => 'cp_metalworks_f7.bsp'],
            ['name' => 'koth_govan_rc2', 'label' => 'koth_govan_rc2', 'file' => 'koth_govan_rc2.bsp'],
            ['name' => 'cp_subbase_b3a', 'label' => 'cp_subbase_b3a', 'file' => 'cp_subbase_b3a.bsp'],
            ['name' => 'koth_bagel_rc12', 'label' => 'koth_bagel_rc12', 'file' => 'koth_bagel_rc12.bsp'],
            ['name' => 'cp_granary_pro_rc17a3', 'label' => 'cp_granary_pro_rc17a3', 'file' => 'cp_granary_pro_rc17a3.bsp'],
            ['name' => 'koth_product_final', 'label' => 'koth_product_final', 'file' => 'koth_product_final.bsp'],
        ];

        $maps9v9 = [
            ['name' => 'pl_swiftwater_final1', 'label' => 'pl_swiftwater_final1', 'file' => 'pl_swiftwater_final1.bsp'],
            ['name' => 'pl_vigil_rc10', 'label' => 'pl_vigil_rc10', 'file' => 'pl_vigil_rc10.bsp'],
            ['name' => 'cp_steel_f12', 'label' => 'cp_steel_f12', 'file' => 'cp_steel_f12.bsp'],
            ['name' => 'pl_upward_f12', 'label' => 'pl_upward_f12', 'file' => 'pl_upward_f12.bsp'],
            ['name' => 'koth_product_final', 'label' => 'koth_product_final', 'file' => 'koth_product_final.bsp'],
            ['name' => 'koth_proot_b5b', 'label' => 'koth_proot_b5b', 'file' => 'koth_proot_b5b.bsp'],
        ];

        $buildList = static function (array $maps, string $sub): array {
            return array_map(static function (array $m) use ($sub): array {
                $path = "storage/etf2l-maps/{$sub}/{$m['file']}";
                $storageAbs = storage_path("app/public/etf2l-maps/{$sub}/{$m['file']}");
                $publicAbs = public_path($path);
                $abs = null;
                if (is_file($storageAbs)) {
                    $abs = $storageAbs;
                    if (! is_file($publicAbs)) {
                        @mkdir(dirname($publicAbs), 0755, true);
                        @copy($storageAbs, $publicAbs);
                    }
                } elseif (is_file($publicAbs)) {
                    $abs = $publicAbs;
                }
                $exists = $abs !== null;
                $size = $exists ? filesize($abs) : null;

                return $m + [
                    'url' => asset($path),
                    'exists' => $exists,
                    'size' => $size,
                    'size_human' => $size !== false && $size !== null ? number_format($size / 1048576, 1).' Mo' : null,
                ];
            }, $maps);
        };

        return view('pages.etf2l-maps', [
            'title' => 'Highlander France - Maps ETF2L 6v6 & 9v9',
            'description' => 'Toutes les maps officielles ETF2L de la saison en cours en 6v6 et Highlander 9v9, hébergées sur Highlander France. Téléchargement direct en .bsp.',
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'ETF2L', 'url' => site_url().'/matchs'],
                ['name' => 'Maps', 'url' => site_url().'/etf2l/maps'],
            ],
            'maps6v6' => $buildList($maps6v6, '6v6'),
            'maps9v9' => $buildList($maps9v9, '9v9'),
        ]);
    }

    /**
     * Historique des matchs passés des équipes FR (GET /matchs).
     */
    public function etf2lMatches(Request $request): View
    {
        $perPage = 20;
        $page = max(1, (int) $request->query('page', '1'));

        $repo = new Etf2lRepository;
        $total = $repo->countPastMatches();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        $matches = $repo->pastMatches($perPage, ($page - 1) * $perPage);

        return view('pages.etf2l-matches', [
            'title' => 'Highlander France - Matchs des équipes FR | ETF2L',
            'description' => 'Historique des matchs ETF2L des équipes françaises : scores par carte, résultats et rosters.',
            'matches' => $matches,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalMatches' => $total,
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'Matchs ETF2L', 'url' => site_url().'/matchs'],
            ],
        ]);
    }

    /**
     * Liste des joueurs inscrits (GET /joueurs) : connexion Steam requise.
     * Recherche par pseudo, tri (alphabétique / division HL / division 6s)
     * et pagination manuelle (même pattern que /matchs).
     */
    public function joueurs(Request $request): View|RedirectResponse
    {
        if (! Auth::isLoggedIn()) {
            return redirect('/login');
        }

        $search = trim((string) $request->query('q', ''));
        if (mb_strlen($search) > 50) {
            $search = mb_substr($search, 0, 50);
        }

        $sort = (string) $request->query('sort', 'hl');
        if (! in_array($sort, ['name', 'hl', 'div6'], true)) {
            $sort = 'hl';
        }

        $dir = strtolower((string) $request->query('dir', 'asc'));
        $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc';

        $perPage = 25;
        $page = max(1, (int) $request->query('page', '1'));

        $repo = new PlayerRepository;
        $result = $repo->registeredPlayers($search, $sort, $dir, $perPage, $page);

        $totalPages = max(1, (int) ceil($result['total'] / $perPage));
        $page = min($page, $totalPages);

        // Classes les plus jouées pour les joueurs de la page courante.
        $topClasses = $repo->topClasses(array_column($result['rows'], 'steamid'));

        $franceMap = FranceBadgeService::bulkForSteamId3(array_column($result['rows'], 'steamid'));

        foreach ($result['rows'] as &$row) {
            $row['final_name'] = ($row['display_name'] ?? '') !== ''
                ? $row['display_name']
                : ($row['name'] ?? 'Joueur');
            $steamid64 = SteamId::toSteamId64((string) $row['steamid']);
            $row['profile_url'] = $steamid64 !== null ? '/profile/'.$steamid64 : null;
            $row['classes'] = $topClasses[$row['steamid']] ?? [];
            $hasCountry = ! empty($row['country']) && $row['country'] !== 'unknown';
            $row['flag_url'] = $hasCountry ? CountryFlags::flag((string) $row['country']) : null;
            $row['country_label'] = $hasCountry ? (config('hlfr.countries')[(string) $row['country']] ?? ucfirst((string) $row['country'])) : null;
            $row['franceBadges'] = $franceMap[$row['steamid']] ?? ['6v6' => false, 'highlander' => false];
        }
        unset($row);

        $description = 'Liste des joueurs inscrits sur Highlander France : divisions ETF2L (Highlander et 6v6), classes les plus jouées et nationalité.';

        return view('pages.joueurs', [
            'title' => 'Highlander France - Joueurs inscrits',
            'description' => $description,
            'noIndex' => true,
            'players' => $result['rows'],
            'totalPlayers' => $result['total'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
        ]);
    }

    public function staff(): View
    {
        $members = (new PlayerRepository)->staffMembers();

        $groups = ['founders' => [], 'mentors' => [], 'mixers' => [], 'moderators' => []];
        $roleMap = [
            'founders' => 'is_founder',
            'mentors' => 'is_mentor',
            'mixers' => 'is_mixer',
            'moderators' => 'is_moderator',
        ];

        foreach ($members as $member) {
            $member = (array) $member;
            $member['final_name'] = ! empty($member['display_name']) ? $member['display_name'] : $member['name'];
            $member['profile_url'] = '/profile/'.SteamId::toSteamId64($member['steamid']);

            foreach ($roleMap as $group => $column) {
                if ((int) $member[$column] === 1) {
                    $groups[$group][] = $member;
                }
            }
        }

        return view('pages.staff', [
            'title' => "Highlander France - L'équipe",
            'description' => "Découvrez l'équipe Highlander France : fondateurs, modérateurs, mentors et lanceurs de mix qui animent la communauté TF2 9v9 francophone.",
            'groups' => $groups,
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => "L'équipe", 'url' => site_url().'/staff'],
            ],
        ]);
    }

    public function hallOfFame(): View
    {
        $initialLeaderboard = [];
        $cacheFile = hlfr_data_path('leaderboard_cache_9v9.json');
        if (is_file($cacheFile)) {
            $decoded = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($decoded)) {
                $initialLeaderboard = array_slice($decoded, 0, 18);
            }
        }

        return view('pages.hall-of-fame', [
            'title' => 'Highlander France - Hall of Fame',
            'description' => 'Classement Hall of Fame Highlander France : top joueurs TF2 en 9v9 et 6v6 par matchs, kills, heal et DPM. Stats issues des logs officiels.',
            'scripts' => ['/_js/leaderboard.js', '/_js/search_players.js'],
            'initialLeaderboard' => $initialLeaderboard,
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'Hall of Fame', 'url' => site_url().'/hall-of-fame'],
            ],
        ]);
    }

    public function matchLogs(): View
    {
        $initialLogs = [];
        try {
            $logs = (new LogsTfApi)->filteredLogs();
            if (count($logs) > 4) {
                $logs = array_slice($logs, 0, count($logs) - 4);
            }
            $initialLogs = array_slice($logs, 0, 10);
        } catch (\Throwable) {
            $initialLogs = [];
        }

        return view('pages.match-logs', [
            'title' => 'Highlander France - Logs des Matchs',
            'description' => 'Logs des matchs Highlander France : historique complet des mixes 9v9 et 6v6, filtres par date et carte, scores et stats détaillées.',
            'isAdmin' => Auth::isAdmin(),
            'initialLogs' => $initialLogs,
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'Match Stats', 'url' => site_url().'/match-logs'],
            ],
        ]);
    }

    public function privacy(): View
    {
        return view('pages.privacy', [
            'title' => 'Highlander France - Politique de Confidentialité',
            'description' => 'Politique de confidentialité Highlander France : données Steam, logs.tf, cookies, services tiers et droits RGPD. Hébergé chez Pulseheberg.',
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'Confidentialité', 'url' => site_url().'/confidentialite'],
            ],
        ]);
    }

    /**
     * GET /sitemap.xml — sitemap dynamique (pages statiques + logs + profils).
     */
    public function sitemap(): Response
    {
        $xml = Cache::remember('sitemap', now()->addHour(), function (): string {
            $logs = (new MatchLogRepository)->sitemapLogs();
            $players = DB::table('player_matches')->distinct()->pluck('steamid')->all();
            if ($players === []) {
                $players = (new PlayerRepository)->allSteamIds();
            }
            $etf2lMatches = (new Etf2lRepository)->sitemapMatches();

            $base = site_url();

            // Pages statiques : [path, priority, changefreq]
            $staticPages = [
                '/' => [1.0, 'always'],
                '/staff' => [0.8, 'monthly'],
                '/hall-of-fame' => [0.8, 'daily'],
                '/match-logs' => [0.8, 'daily'],
                '/matchs' => [0.8, 'daily'],
                '/etf2l/maps' => [0.7, 'weekly'],
                '/confidentialite' => [0.3, 'yearly'],
            ];

            $block = static function (string $url, ?string $lastmod, float $priority, string $changefreq): string {
                $out = "  <url>\n    <loc>".e($url)."</loc>\n";
                if ($lastmod !== null) {
                    $out .= '    <lastmod>'.$lastmod."</lastmod>\n";
                }
                $out .= '    <priority>'.rtrim(rtrim(number_format($priority, 1), '0'), '.')."</priority>\n";
                $out .= '    <changefreq>'.$changefreq."</changefreq>\n  </url>";

                return $out;
            };

            $lines = [];
            foreach ($staticPages as $path => [$priority, $change]) {
                $lines[] = $block($base.$path, null, $priority, $change);
            }

            // Dernier match comme référence de fraîcheur pour /match-logs et l'accueil.
            $lastMatchDate = null;
            if ($logs !== []) {
                foreach ($logs as $log) {
                    if (is_int($log['date'])) {
                        $lastMatchDate = $log['date'];
                        break;
                    }
                }
            }

            foreach ($logs as $log) {
                $lastmod = null;
                if (is_int($log['date']) && $log['date'] > 0) {
                    $lastmod = date('Y-m-d', $log['date']);
                } elseif ($lastMatchDate !== null) {
                    $lastmod = date('Y-m-d', $lastMatchDate);
                }
                $lines[] = $block($base.'/log/'.$log['id'], $lastmod, 0.5, 'weekly');
            }

            foreach ($players as $steamid3) {
                $steamid64 = SteamId::toSteamId64($steamid3);
                if ($steamid64 === null) {
                    continue;
                }
                $lines[] = $block($base.'/profile/'.$steamid64, null, 0.4, 'monthly');
            }

            // Matchs ETF2L à venir (contenu éphémère mais indexable tant qu'ils existent).
            foreach ($etf2lMatches as $match) {
                $lastmod = isset($match['match_date']) && is_numeric($match['match_date']) && (int) $match['match_date'] > 0
                    ? date('Y-m-d', (int) $match['match_date'])
                    : null;
                $lines[] = $block($base.'/match/'.(int) $match['match_id'], $lastmod, 0.6, 'daily');
            }

            return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
                .implode("\n", $lines)."\n</urlset>";
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /**
     * Page d'un match en direct (GET /live/{server}).
     * Source : cache alimenté par le plugin hlfr_live_match.
     */
    public function liveMatch(Request $request): View
    {
        $server = (string) $request->route('server', '');
        $entry = $server !== '' ? LiveMatches::get($server) : null;

        if ($entry === null) {
            abort(404);
        }

        $entry = LiveMatches::enrich($entry);
        $mapDisplay = MatchFormat::mapDisplay((string) ($entry['map'] ?? ''));

        $redPlayers = [];
        $bluePlayers = [];

        foreach (($entry['players'] ?? []) as $p) {
            if (($p['team'] ?? '') === 'red') {
                $redPlayers[] = $p;
            } else {
                $bluePlayers[] = $p;
            }
        }

        $redScore = (int) ($entry['scores']['red'] ?? 0);
        $blueScore = (int) ($entry['scores']['blue'] ?? 0);

        return view('pages.live-match', [
            'title' => 'Highlander France - '.$mapDisplay.' | En direct',
            'description' => 'Match TF2 en direct sur '.$mapDisplay.' : scores RED/BLU et joueurs en temps réel sur Highlander France.',
            'noIndex' => true,
            'server' => $server,
            'entry' => $entry,
            'mapDisplay' => $mapDisplay,
            'redPlayers' => $redPlayers,
            'bluePlayers' => $bluePlayers,
            'redScore' => $redScore,
            'blueScore' => $blueScore,
            'playerCount' => count($redPlayers) + count($bluePlayers),
            'startedAt' => date('H:i', (int) ($entry['started_at'] ?? time())),
        ]);
    }

    /**
     * Détail d'un match (GET /log/{id}).
     */
    public function matchLog(Request $request): View
    {
        $logId = (int) $request->route('id', 0);

        if ($logId <= 0) {
            abort(400);
        }

        $repo = new MatchLogRepository;

        if (in_array($logId, $repo->blacklistedIds(), true)) {
            abort(404);
        }

        $log = $repo->matchDetail($logId);

        if ($log === null) {
            abort(404);
        }

        $players = $log['players'];
        $gameMode = $log['game_mode'] === '6S' ? '6S' : '9V9';
        $gameModeLabel = $gameMode === '6S' ? 'Sixes (6v6)' : 'Highlander (9v9)';

        $partition = MatchFormat::partitionPlayers($players);
        $redPlayers = $partition['red'];
        $bluePlayers = $partition['blue'];
        $otherPlayers = $partition['other'];
        $hasTeamData = $partition['hasTeams'];

        $redScore = $log['red_score'];
        $blueScore = $log['blue_score'];

        if ($redScore !== null || $blueScore !== null) {
            $hasTeamData = true;
        }

        $teamPanels = [
            ['key' => 'blue', 'name' => 'BLU', 'players' => $bluePlayers, 'score' => $blueScore, 'otherScore' => $redScore],
            ['key' => 'red', 'name' => 'RED', 'players' => $redPlayers, 'score' => $redScore, 'otherScore' => $blueScore],
        ];

        if ($redScore !== null && $blueScore !== null && $redScore > $blueScore) {
            $teamPanels = array_reverse($teamPanels);
        }

        foreach ($teamPanels as &$panel) {
            $panel['result'] = MatchFormat::teamResult(
                is_null($panel['score']) ? null : (int) $panel['score'],
                is_null($panel['otherScore']) ? null : (int) $panel['otherScore']
            );
        }
        unset($panel);

        $matchDate = $log['date'] !== null ? date('d/m/Y à H:i', (int) $log['date']) : null;
        $mapDisplay = MatchFormat::mapDisplay((string) $log['map_name']);
        $playerCount = count($players);
        $logDescription = 'Log TF2 '.$mapDisplay.' '.$gameModeLabel.' du '.($matchDate ?? 'match Highlander France').' : '.$playerCount.' joueurs, scores '.($redScore ?? '?').'-'.($blueScore ?? '?').', stats kills, DPM et heal. Voir sur logs.tf #'.$logId.'.';

        return view('pages.match-log', [
            'title' => 'Highlander France - '.$mapDisplay.' | '.$gameModeLabel,
            'description' => $logDescription,
            'ogType' => 'article',
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'Match Stats', 'url' => site_url().'/match-logs'],
                ['name' => $mapDisplay.' #'.$logId, 'url' => site_url().'/log/'.$logId],
            ],
            'logId' => $logId,
            'mapDisplay' => $mapDisplay,
            'gameMode' => $gameMode,
            'gameModeLabel' => $gameModeLabel,
            'matchDate' => $matchDate,
            'durationDisplay' => MatchFormat::duration((int) $log['length']),
            'playerCount' => $playerCount,
            'hasTeamData' => $hasTeamData,
            'redScore' => $redScore,
            'blueScore' => $blueScore,
            'players' => $players,
            'teamPanels' => $teamPanels,
            'otherPlayers' => $otherPlayers,
            'isAdmin' => Auth::isAdmin(),
        ]);
    }
}
