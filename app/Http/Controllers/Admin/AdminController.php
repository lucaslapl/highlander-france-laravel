<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRepository;
use App\Models\MatchLogRepository;
use App\Models\PlayerRepository;
use App\Services\ApiStatus;
use App\Services\Auth;
use App\Services\MatchFormat;
use App\Services\SteamId;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pages du panel d'administration (accès strict réservé aux admins).
 */
final class AdminController extends Controller
{
    private AdminRepository $repo;

    public function __construct()
    {
        $this->repo = new AdminRepository();
    }

    /**
     * GET /admin/dashboard
     */
    public function dashboard(Request $request): View
    {
        Auth::requireAdmin();

        $dashboard = $this->repo->dashboard();

        return view('admin.dashboard', [
            'title' => 'Highlander France - Panel Admin',
            'description' => 'Panel d\'administration Highlander France.',
            'styles' => ['/_css/admin.css'],
            'scripts' => [
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
                '/_js/admin_charts.js',
                '/_js/admin_player_search.js',
            ],
            'dashboardData' => [
                'registrations' => $dashboard['registrations'],
                'matchesPerDay' => $dashboard['matchesPerDay'],
                'modes' => $dashboard['modes'],
            ],
            'dashboard' => $dashboard,
            'apiStatuses' => (new ApiStatus())->get($request->query('refresh_apis', '') === '1'),
            'techTeam' => $this->repo->technicalTeam(),
        ]);
    }

    /**
     * GET /admin/list-staff
     */
    public function listStaff(): View
    {
        Auth::requireAdmin();

        try {
            $staff = \Illuminate\Support\Facades\DB::table('players_info')
                ->select('steamid', 'name', 'display_name', 'avatar', 'is_founder', 'is_moderator', 'is_mentor', 'is_mixer', 'is_admin')
                ->where(function ($q): void {
                    $q->where('is_founder', 1)
                        ->orWhere('is_moderator', 1)
                        ->orWhere('is_mentor', 1)
                        ->orWhere('is_mixer', 1)
                        ->orWhere('is_admin', 1);
                })
                ->orderByDesc('is_admin')
                ->orderBy('display_name')
                ->orderBy('name')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        } catch (\PDOException) {
            $staff = [];
        }

        foreach ($staff as &$member) {
            $member['steamid64'] = SteamId::toSteamId64($member['steamid']);
            $member['final_name'] = !empty($member['display_name']) ? $member['display_name'] : $member['name'];
        }
        unset($member);

        return view('admin.list_staff', [
            'title' => 'Admin - Liste de l\'équipe',
            'description' => 'Gestion de l\'équipe staff Highlander France.',
            'styles' => ['/_css/admin.css'],
            'staff' => $staff,
        ]);
    }

    /**
     * GET /admin/manage-blacklist
     */
    public function manageBlacklist(): View
    {
        Auth::requireAdmin();

        $blacklist = $this->repo->blacklist();
        foreach ($blacklist as &$entry) {
            $entry['admin_name'] = $this->repo->adminDisplayName((string) ($entry['added_by'] ?? ''));
        }
        unset($entry);

        return view('admin.manage_blacklist', [
            'title' => 'Admin - Gestion des logs blacklistés',
            'description' => 'Blacklist des logs logs.tf exclus des statistiques.',
            'styles' => ['/_css/admin.css'],
            'blacklist' => $blacklist,
            'totalBlacklisted' => count($blacklist),
        ]);
    }

    /**
     * GET /admin/manage-player/{steamid}
     */
    public function managePlayer(Request $request): View
    {
        Auth::requireAdmin();

        $targetSteamid = (string) ($request->route('steamid') ?? $request->query('steamid', ''));

        if ($targetSteamid === '' || ! preg_match('/^\d{17}$/', $targetSteamid)) {
            abort(400);
        }

        $steamid3 = SteamId::toSteamId3($targetSteamid);
        $player = (new PlayerRepository())->findById($steamid3);

        if ($player === null) {
            abort(404);
        }

        return view('admin.manage_player', [
            'title' => 'Admin - Gérer ' . ($player['display_name'] ?? $player['name']),
            'description' => 'Panel d\'édition de compte utilisateur.',
            'styles' => ['/_css/admin.css'],
            'target' => [
                'steamid64' => $targetSteamid,
                'steamid3' => $steamid3,
                'player' => $player,
                'final_name' => !empty($player['display_name']) ? $player['display_name'] : $player['name'],
                'current_country' => !empty($player['country']) ? strtolower((string) $player['country']) : 'unknown',
            ],
        ]);
    }
}
