<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Panorama des overlays OBS : liste des équipes / joueurs / matchs / logs avec
 * aperçu iframe et URL à coller dans OBS (source navigateur).
 */
final class AdminOverlayController extends Controller
{
    public function index(): View
    {
        Auth::requireAdmin();

        $options = $this->options();

        return view('admin.overlays', [
            'title' => 'Admin - Overlays OBS',
            'description' => 'URLs des overlays OBS à copier dans OBS Studio.',
            'styles' => ['/_css/admin.css'],
            'scripts' => ['/_js/admin_overlays.js'],
            'teams' => $options['teams'],
            'franceTeams' => $options['franceTeams'],
            'players' => $options['players'],
            'matches' => $options['matches'],
            'logs' => $options['logs'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $config = config('hlfr.france_teams', []);

        $france = [];
        foreach (['6v6' => '6s', 'highlander' => '9v9'] as $key => $category) {
            foreach (array_filter(array_map('intval', (array) ($config[$key] ?? []))) as $teamId) {
                $france[] = [
                    'team_id' => $teamId,
                    'name' => 'Équipe de France '.($category === '6s' ? '6v6' : 'Highlander'),
                    'category' => $category,
                ];
            }
        }

        $teams = DB::table('etf2l_teams')
            ->orderBy('name')
            ->get()
            ->map(static fn ($row): array => [
                'team_id' => (int) $row->team_id,
                'name' => (string) $row->name,
                'category' => null,
            ])
            ->all();

        $players = DB::table('france_national_players')
            ->join('players_info as pi', 'pi.steamid', '=', 'france_national_players.steamid')
            ->select('france_national_players.steamid64', 'pi.name', 'pi.display_name')
            ->distinct()
            ->orderBy('pi.display_name')
            ->get()
            ->map(static fn ($row): array => [
                'steamid64' => (string) $row->steamid64,
                'name' => (string) ($row->display_name ?? $row->name ?? $row->steamid64),
            ])
            ->all();

        $matches = DB::table('etf2l_matches')
            ->whereNotNull('r1')
            ->whereNotNull('r2')
            ->orderByDesc('match_date')
            ->limit(50)
            ->get()
            ->map(static fn ($row): array => [
                'match_id' => (int) $row->match_id,
                'label' => (string) $row->team1_name.' vs '.$row->team2_name,
            ])
            ->all();

        $logs = DB::table('official_logs')
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(static fn ($row): array => [
                'log_id' => (int) $row->log_id,
                'category' => (string) $row->category,
            ])
            ->all();

        return [
            'franceTeams' => $france,
            'teams' => $teams,
            'players' => $players,
            'matches' => $matches,
            'logs' => $logs,
        ];
    }
}
