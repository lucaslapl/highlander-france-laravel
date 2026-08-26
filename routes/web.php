<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminApiController;
use App\Http\Controllers\Admin\AdminApiTestController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminCronController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
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
Route::get('/confidentialite', [PageController::class, 'privacy']);
Route::get('/sitemap.xml', [PageController::class, 'sitemap']);

// ─── API JSON ────────────────────────────────────────────────────────────────
Route::prefix('api')->group(function (): void {
    Route::get('/index-stats', [ApiController::class, 'indexStats']);
    Route::get('/logs', [ApiController::class, 'logs']);
    Route::get('/leaderboard', [ApiController::class, 'leaderboard']);
    Route::get('/search-players', [ApiController::class, 'searchPlayers']);
    Route::get('/live-matches', [ApiController::class, 'liveMatches']);
    Route::get('/twitch-live', [ApiController::class, 'twitchLive']);
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
Route::get('/profile/dashboard', [ProfileController::class, 'dashboard']);
Route::get('/profile/edit', [ProfileController::class, 'edit']);
Route::get('/profile/{steamid}', [ProfileController::class, 'profil']);
Route::permanentRedirect('/profile/profil', '/profile/dashboard');
Route::post('/profile/update-name', [ProfileController::class, 'updateName']);
Route::post('/profile/update-country', [ProfileController::class, 'updateCountry']);
Route::post('/profile/update-links', [ProfileController::class, 'updateLinks']);
Route::post('/profile/update-personal-info', [ProfileController::class, 'updatePersonalInfo']);
