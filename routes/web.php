<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminApiController;
use App\Http\Controllers\Admin\AdminApiTestController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminCronController;
use App\Http\Controllers\Admin\AdminGuideController;
use App\Http\Controllers\Admin\AdminMapController;
use App\Http\Controllers\Admin\AdminPlayerStatsController;
use App\Http\Controllers\Admin\AdminTeamController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\ManagedTeamController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServerHookController;
use Illuminate\Support\Facades\Route;

// ─── Pages publiques ──────────────────────────────────────────────────────────
Route::get('/', [PageController::class, 'home']);
Route::permanentRedirect('/index', '/');
Route::get('/staff', [PageController::class, 'staff']);
Route::get('/joueurs', [PageController::class, 'joueurs']);
Route::get('/hall-of-fame', [PageController::class, 'hallOfFame']);
Route::get('/match-logs', [PageController::class, 'matchLogs']);
Route::get('/log/{id}', [PageController::class, 'matchLog'])->whereNumber('id');
Route::permanentRedirect('/log/match-log', '/match-logs');
Route::get('/match/{id}', [PageController::class, 'etf2lMatch'])->whereNumber('id');
Route::get('/matchs', [PageController::class, 'etf2lMatches']);
Route::get('/etf2l/maps', [PageController::class, 'etf2lMaps']);
Route::get('/confidentialite', [PageController::class, 'privacy']);
Route::get('/guides', [GuideController::class, 'index']);
Route::get('/guides/{slug}', [GuideController::class, 'show'])->where('slug', '[a-z0-9-]+');
Route::get('/faq', [GuideController::class, 'faq']);
Route::get('/equipes', [ManagedTeamController::class, 'index']);
Route::get('/equipes/{slug}', [ManagedTeamController::class, 'show'])->where('slug', '[a-z0-9-]+');
Route::get('/equipes/{slug}/editer', [ManagedTeamController::class, 'edit'])->where('slug', '[a-z0-9-]+');
Route::post('/equipes/{slug}/editer', [ManagedTeamController::class, 'update'])->where('slug', '[a-z0-9-]+');
Route::post('/equipes/{slug}/logo', [ManagedTeamController::class, 'logo'])->where('slug', '[a-z0-9-]+');
Route::post('/equipes/{slug}/logo/supprimer', [ManagedTeamController::class, 'logoDelete'])->where('slug', '[a-z0-9-]+');
Route::get('/logo/team/{id}/{file}', [ManagedTeamController::class, 'logoFile'])->whereNumber('id')->where('file', '[a-zA-Z0-9._-]+');
Route::post('/equipes/{slug}/membres/ajouter', [ManagedTeamController::class, 'memberAdd'])->where('slug', '[a-z0-9-]+');
Route::post('/equipes/{slug}/membres/{memberId}/modifier', [ManagedTeamController::class, 'memberUpdate'])->where('slug', '[a-z0-9-]+')->whereNumber('memberId');
Route::post('/equipes/{slug}/membres/{memberId}/retirer', [ManagedTeamController::class, 'memberRemove'])->where('slug', '[a-z0-9-]+')->whereNumber('memberId');
Route::get('/sitemap.xml', [PageController::class, 'sitemap']);

// ─── API JSON ────────────────────────────────────────────────────────────────
Route::prefix('api')->group(function (): void {
    Route::get('/index-stats', [ApiController::class, 'indexStats']);
    Route::get('/logs', [ApiController::class, 'logs']);
    Route::get('/leaderboard', [ApiController::class, 'leaderboard']);
    Route::get('/search-players', [ApiController::class, 'searchPlayers']);
    Route::get('/live-matches', [ApiController::class, 'liveMatches']);
    Route::get('/twitch-live', [ApiController::class, 'twitchLive']);
    Route::get('/twitch-sidebar', [ApiController::class, 'twitchSidebar']);
    Route::get('/profile-stats', [ProfileController::class, 'profileStats']);

    // Endpoints admin (session + rôle admin requis).
    Route::middleware('admin')->group(function (): void {
        Route::post('/admin/blacklist', [AdminApiController::class, 'blacklist']);
        Route::post('/admin/match-mode', [AdminApiController::class, 'matchMode']);
        Route::post('/admin/player-update', [AdminApiController::class, 'playerUpdate']);
    });
});

// ─── Webhook serveurs de match (plugin SourceMod hlfr_match_log) ─────────────
// Authentifiés par token partagé : exemptés de CSRF (cf. bootstrap/app.php).
Route::post('/api/server/match-ended', [ServerHookController::class, 'matchEnded']);
Route::post('/api/server/live-status', [ServerHookController::class, 'liveStatus']);

// ─── Webhook bot Discord (compteur de membres du serveur) ────────────────────
Route::post('/api/discord/member-count', [ServerHookController::class, 'discordMemberCount']);

// ─── Match en direct ─────────────────────────────────────────────────────────
Route::get('/live/{server}', [PageController::class, 'liveMatch']);

// ─── Panel admin (accès strict réservé aux admins) ───────────────────────────
Route::middleware('admin')->prefix('admin')->group(function (): void {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/list-staff', [AdminController::class, 'listStaff']);
    Route::get('/manage-blacklist', [AdminController::class, 'manageBlacklist']);
    Route::get('/manage-player/{steamid}', [AdminController::class, 'managePlayer'])->where('steamid', '[0-9]{17}');
    Route::get('/manage-player', [AdminController::class, 'managePlayer']);
    Route::any('/match-logs', [AdminCronController::class, 'matchLogs']);
    Route::any('/run-cron-manual', [AdminCronController::class, 'runCronManual']);
    Route::any('/view-logs', [AdminCronController::class, 'viewLogs']);
    Route::get('/maps', [AdminMapController::class, 'index']);
    Route::post('/maps/upload-pending', [AdminMapController::class, 'uploadPending']);
    Route::post('/maps/store', [AdminMapController::class, 'store']);
    Route::post('/maps/reorder', [AdminMapController::class, 'reorder']);
    Route::post('/maps/{id}/update', [AdminMapController::class, 'update'])->whereNumber('id');
    Route::post('/maps/{id}/delete', [AdminMapController::class, 'delete'])->whereNumber('id');
    Route::post('/maps/{id}/toggle', [AdminMapController::class, 'toggle'])->whereNumber('id');
    Route::get('/guides', [AdminGuideController::class, 'guides']);
    Route::get('/guides/nouveau', [AdminGuideController::class, 'guideEdit']);
    Route::post('/guides/store', [AdminGuideController::class, 'guideStore']);
    Route::get('/guides/{id}/edit', [AdminGuideController::class, 'guideEdit'])->whereNumber('id');
    Route::post('/guides/{id}/update', [AdminGuideController::class, 'guideUpdate'])->whereNumber('id');
    Route::post('/guides/{id}/delete', [AdminGuideController::class, 'guideDelete'])->whereNumber('id');
    Route::post('/guides/{id}/toggle', [AdminGuideController::class, 'guideToggle'])->whereNumber('id');
    Route::get('/faq/nouveau', [AdminGuideController::class, 'faqEdit']);
    Route::post('/faq/store', [AdminGuideController::class, 'faqStore']);
    Route::get('/faq/{id}/edit', [AdminGuideController::class, 'faqEdit'])->whereNumber('id');
    Route::post('/faq/{id}/update', [AdminGuideController::class, 'faqUpdate'])->whereNumber('id');
    Route::post('/faq/{id}/delete', [AdminGuideController::class, 'faqDelete'])->whereNumber('id');
    Route::post('/faq/{id}/toggle', [AdminGuideController::class, 'faqToggle'])->whereNumber('id');
    Route::get('/stats-joueur', [AdminPlayerStatsController::class, 'index'])->name('admin.stats-joueur');
    Route::post('/stats-joueur/start', [AdminPlayerStatsController::class, 'start']);
    Route::get('/stats-joueur/status/{token}', [AdminPlayerStatsController::class, 'status'])->where('token', '[a-z0-9]{16}');
    Route::get('/stats-joueur/teams/search', [AdminPlayerStatsController::class, 'searchTeams']);
    Route::post('/stats-joueur/equipe/prepare', [AdminPlayerStatsController::class, 'prepareTeam']);
    Route::post('/stats-joueur/equipe/start', [AdminPlayerStatsController::class, 'startTeam']);
    Route::get('/equipes', [AdminTeamController::class, 'index']);
    Route::get('/equipes/search', [AdminTeamController::class, 'search']);
    Route::post('/equipes/store', [AdminTeamController::class, 'store']);
    Route::get('/equipes/{id}', [AdminTeamController::class, 'show'])->whereNumber('id');
    Route::post('/equipes/{id}/update', [AdminTeamController::class, 'update'])->whereNumber('id');
    Route::post('/equipes/{id}/resync', [AdminTeamController::class, 'resync'])->whereNumber('id');
    Route::post('/equipes/{id}/logo', [AdminTeamController::class, 'logo'])->whereNumber('id');
    Route::post('/equipes/{id}/logo/delete', [AdminTeamController::class, 'logoDelete'])->whereNumber('id');
    Route::post('/equipes/{id}/toggle', [AdminTeamController::class, 'toggle'])->whereNumber('id');
    Route::post('/equipes/{id}/delete', [AdminTeamController::class, 'delete'])->whereNumber('id');
    Route::post('/equipes/{id}/members/add', [AdminTeamController::class, 'memberAdd'])->whereNumber('id');
    Route::post('/equipes/{id}/members/{memberId}/update', [AdminTeamController::class, 'memberUpdate'])->whereNumber('id')->whereNumber('memberId');
    Route::post('/equipes/{id}/members/{memberId}/leader', [AdminTeamController::class, 'memberLeader'])->whereNumber('id')->whereNumber('memberId');
    Route::post('/equipes/{id}/members/{memberId}/remove', [AdminTeamController::class, 'memberRemove'])->whereNumber('id')->whereNumber('memberId');
    Route::get('/api-test', [AdminApiTestController::class, 'page']);
    Route::post('/api-test/live/start', [AdminApiTestController::class, 'liveStart']);
    Route::post('/api-test/live/heartbeat', [AdminApiTestController::class, 'liveHeartbeat']);
    Route::post('/api-test/live/end', [AdminApiTestController::class, 'liveEnd']);
    Route::post('/api-test/live/purge', [AdminApiTestController::class, 'livePurge']);
    Route::post('/api-test/etf2l', [AdminApiTestController::class, 'etf2lUpsert']);
    Route::post('/api-test/etf2l/delete', [AdminApiTestController::class, 'etf2lDelete']);
    Route::post('/api-test/twitch', [AdminApiTestController::class, 'twitchSimulate']);
    Route::post('/api-test/twitch/reset', [AdminApiTestController::class, 'twitchReset']);
});

// ─── Authentification Steam ──────────────────────────────────────────────────
Route::get('/login', [AuthController::class, 'login']);
Route::get('/auth/callback', [AuthController::class, 'callback']);
Route::post('/logout', [AuthController::class, 'logout']);

// ─── Profils ─────────────────────────────────────────────────────────────────
// Proxy de l'avatar Steam (CDN qui renvoie des 429 sur les requêtes directes du navigateur).
Route::get('/img/avatar/{steamid}', [ProfileController::class, 'avatar'])->where('steamid', '[0-9]{17}');
Route::get('/profile/dashboard', [ProfileController::class, 'dashboard']);
Route::get('/profile/edit', [ProfileController::class, 'edit']);
Route::get('/profile/{steamid}', [ProfileController::class, 'profil']);
Route::permanentRedirect('/profile/profil', '/profile/dashboard');
Route::post('/profile/update-name', [ProfileController::class, 'updateName']);
Route::post('/profile/update-country', [ProfileController::class, 'updateCountry']);
Route::post('/profile/update-links', [ProfileController::class, 'updateLinks']);
Route::post('/profile/update-personal-info', [ProfileController::class, 'updatePersonalInfo']);
