<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatchLogRepository;
use App\Models\OfficialLogsRepository;
use App\Services\Auth;
use App\Services\OfficialLogsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestion des logs.tf officiels de ligue (rattachement aux matchs ETF2L
 * et aux équipes de France). Accessible aux admins uniquement.
 */
final class AdminLigueLogsController extends Controller
{
    private OfficialLogsService $service;

    public function __construct()
    {
        $this->service = new OfficialLogsService;
    }

    public function index(): View
    {
        Auth::requireAdmin();

        $matches = DB::table('etf2l_matches')
            ->whereNotNull('r1')
            ->whereNotNull('r2')
            ->orderByDesc('match_date')
            ->limit(60)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        $repo = new OfficialLogsRepository;
        $blacklisted = (new MatchLogRepository)->blacklistedIds();
        $blacklistedTeams = DB::table('team_blacklist')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($row): array {
                $name = DB::table('etf2l_teams')->where('team_id', (int) $row->team_id)->value('name');

                return [
                    'team_id' => (int) $row->team_id,
                    'name' => $name !== null ? (string) $name : 'Équipe #'.(int) $row->team_id,
                    'reason' => (string) ($row->reason ?? ''),
                    'added_by' => (string) ($row->added_by ?? ''),
                    'created_at' => $row->created_at,
                ];
            })
            ->all();

        foreach ($matches as &$match) {
            $match['logs'] = $repo->logsForMatch((int) $match['match_id']);
        }
        unset($match);

        return view('admin.ligue_logs', [
            'title' => 'Admin - Logs officiels de ligue',
            'description' => 'Rattachement des logs.tf des matchs officiels ETF2L.',
            'styles' => ['/_css/admin.css'],
            'scripts' => ['/_js/admin_ligue_logs.js'],
            'matches' => $matches,
            'blacklisted' => $blacklisted,
            'blacklistedTeams' => $blacklistedTeams,
            'teams' => $this->teamOptions(),
            'franceTeams' => $this->franceTeamOptions(),
        ]);
    }

    /**
     * Rattache manuellement un logs.tf à un match ETF2L.
     */
    public function attachToMatch(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'match_id' => ['required', 'integer', 'min:1'],
            'log_url' => ['required', 'string', 'max:255'],
        ]);

        $matchId = (int) $data['match_id'];
        $logId = $this->parseLogId((string) $data['log_url']);

        if ($logId === null) {
            return back()->with('error', 'URL ou ID logs.tf invalide.');
        }

        $match = DB::table('etf2l_matches')->where('match_id', $matchId)->first();
        if ($match === null) {
            return back()->with('error', 'Match ETF2L introuvable.');
        }

        if ((new OfficialLogsRepository)->exists($logId)) {
            return back()->with('error', "Le log {$logId} est déjà rattaché à un match officiel.");
        }

        $result = $this->service->attachForMatch($matchId, [$logId], 'manual', $this->adminName());

        DB::table('etf2l_matches')->where('match_id', $matchId)->update(['logs_checked_at' => time()]);

        if ($result['attached'] > 0) {
            return back()->with('success', "Log {$logId} rattaché au match {$matchId}.");
        }

        $reason = $result['skipped'][$logId] ?? 'impossible de rattacher le log';

        return back()->with('error', "Log {$logId} non rattaché ({$reason}).");
    }

    /**
     * Scrape la page ETF2L du match à la demande pour récupérer ses logs.
     */
    public function scrapeMatch(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'match_id' => ['required', 'integer', 'min:1'],
        ]);
        $matchId = (int) $data['match_id'];

        $match = DB::table('etf2l_matches')->where('match_id', $matchId)->first();
        if ($match === null) {
            return back()->with('error', 'Match ETF2L introuvable.');
        }

        $result = $this->service->scrapeMatch($matchId);

        if ($result['attached'] > 0) {
            return back()->with('success', $result['attached'].' log(s) officiel(s) récupéré(s) pour le match '.$matchId.'.');
        }

        if ($result['logs'] === []) {
            DB::table('etf2l_matches')->where('match_id', $matchId)->update(['logs_checked_at' => time()]);

            return back()->with('error', 'Aucun log logs.tf trouvé sur la page ETF2L de ce match.');
        }

        return back()->with('info', 'Les logs trouvés étaient déjà rattachés.');
    }

    /**
     * Retire un log de la liste des logs officiels.
     */
    public function detach(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'log_id' => ['required', 'integer', 'min:1'],
        ]);

        (new OfficialLogsRepository)->detach((int) $data['log_id']);

        return back()->with('success', 'Log officiel retiré.');
    }

    /**
     * Rattache un log à une seule équipe (matchs hors du calendrier ETF2L,
     * ex. équipe de France en Nations Cup ou amicaux officiels).
     */
    public function attachTeamLog(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'category' => ['required', 'in:9v9,6s'],
            'log_url' => ['required', 'string', 'max:255'],
        ]);

        $teamId = (int) $data['team_id'];
        $category = (string) $data['category'];
        $logId = $this->parseLogId((string) $data['log_url']);

        if ($logId === null) {
            return back()->with('error', 'URL ou ID logs.tf invalide.');
        }

        if ($this->service->attachTeamLog($logId, $teamId, $category, $this->adminName())) {
            return back()->with('success', "Log {$logId} rattaché à l'équipe {$teamId} ({$category}).");
        }

        return back()->with('error', "Log {$logId} introuvable sur logs.tf.");
    }

    private function parseLogId(string $input): ?int
    {
        if (preg_match('#(?:\/|^)(\d{4,10})\s*$#', trim($input), $m)) {
            return (int) $m[1];
        }
        if (preg_match('#logs\.tf/(\d{4,10})#i', $input, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Blackliste une équipe ETF2L : ses logs officiels sont exclus des overlays.
     */
    public function blacklistTeam(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $teamId = (int) $data['team_id'];

        if (! DB::table('etf2l_teams')->where('team_id', $teamId)->exists()) {
            return back()->with('error', "Équipe ETF2L {$teamId} introuvable.");
        }

        DB::table('team_blacklist')->insertOrIgnore([
            'team_id' => $teamId,
            'reason' => $data['reason'] !== null && $data['reason'] !== '' ? $data['reason'] : null,
            'added_by' => $this->adminName(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Équipe {$teamId} blacklistée : ses logs ne compteront plus dans les stats.");
    }

    /**
     * Retire une équipe de la blacklist.
     */
    public function unblacklistTeam(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
        ]);

        DB::table('team_blacklist')->where('team_id', (int) $data['team_id'])->delete();

        return back()->with('success', 'Équipe retirée de la blacklist.');
    }

    private function adminName(): string
    {
        $player = Auth::player();

        return $player !== null
            ? (string) ($player['display_name'] ?? $player['name'] ?? 'admin')
            : 'admin';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamOptions(): array
    {
        $blacklisted = DB::table('team_blacklist')->pluck('team_id')->map('intval')->all();

        return DB::table('etf2l_teams')
            ->orderBy('name')
            ->get()
            ->reject(static fn ($row): bool => in_array((int) $row->team_id, $blacklisted, true))
            ->map(static fn ($row): array => ['team_id' => (int) $row->team_id, 'name' => (string) $row->name, 'is_france' => false])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function franceTeamOptions(): array
    {
        $config = config('hlfr.france_teams', []);
        $lists = [
            ['key' => '6s', 'ids' => (array) ($config['6v6'] ?? []), 'label' => '6v6'],
            ['key' => '9v9', 'ids' => (array) ($config['highlander'] ?? []), 'label' => 'Highlander (9v9)'],
        ];

        $options = [];
        foreach ($lists as $list) {
            foreach (array_filter(array_map('intval', $list['ids'])) as $teamId) {
                $options[] = [
                    'team_id' => $teamId,
                    'name' => 'Équipe de France '.$list['label'],
                    'is_france' => true,
                    'category' => $list['key'],
                ];
            }
        }

        return $options;
    }
}
