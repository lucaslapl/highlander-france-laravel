<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ComputeTeamStatsJob;
use App\Models\MatchLogRepository;
use App\Models\OfficialMatchRepository;
use App\Services\Auth;
use App\Services\DuelStatsService;
use App\Services\Etf2lHistoryService;
use App\Services\OfficialLogProcessor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Page de stats multi-équipes : saisie de deux IDs d'équipe ETF2L, reproduction
 * de la fenêtre « 3 dernières saisons », rattachement des logs.tf et calcul des
 * stats joueurs/équipe. Remplace les pages « Logs officiels de ligue » et
 * « Overlays ».
 */
final class AdminStatsDuelController extends Controller
{
    private DuelStatsService $service;

    public function __construct()
    {
        $this->service = new DuelStatsService(new Etf2lHistoryService, new OfficialMatchRepository, new OfficialLogProcessor);
    }

    public function index(Request $request): View
    {
        Auth::requireAdmin();

        $t1 = (int) $request->query('t1', 0);
        $t2 = (int) $request->query('t2', 0);
        $mode1 = (string) $request->query('mode1', '');
        $mode2 = (string) $request->query('mode2', '');
        $run = (int) $request->query('run', 0);

        return view('admin.stats_duel', [
            'title' => 'Admin - Stats équipes',
            'description' => 'Stats officielles de deux équipes ETF2L sur leurs 3 dernières saisons.',
            'styles' => ['/_css/admin.css'],
            'scripts' => ['/_js/admin_stats_duel.js'],
            'form' => ['t1' => $t1, 't2' => $t2, 'mode1' => $mode1, 'mode2' => $mode2],
            'run' => $run,
            'error' => null,
            'teamOptions' => $this->teamOptions(),
            'blacklistedTeams' => $this->blacklistedTeams(),
        ]);
    }

    /**
     * POST /admin/stats-duel/run-async — valide, crée une ligne de progression
     * et lance le Job en arrière-plan. Renvoie immédiatement l'identifiant du
     * run pour que le frontend puisse interroger la progression (AJAX).
     */
    public function runAsync(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        try {
            $data = $request->validate([
                'team_1' => ['required', 'integer', 'min:1'],
                'team_2' => ['required', 'integer', 'min:1'],
                'mode_1' => ['nullable', 'in:,9v9,6s'],
                'mode_2' => ['nullable', 'in:,9v9,6s'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'Valeurs invalides.'], 422);
        }

        $runId = DB::table('stats_duel_runs')->insertGetId([
            't1' => (int) $data['team_1'],
            't2' => (int) $data['team_2'],
            'mode1' => (string) ($data['mode_1'] ?? '') !== '' ? (string) $data['mode_1'] : null,
            'mode2' => (string) ($data['mode_2'] ?? '') !== '' ? (string) $data['mode_2'] : null,
            'status' => 0,
            'progress' => 0,
            'message' => 'Préparation…',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ComputeTeamStatsJob::dispatchAfterResponse(
            t1: (int) $data['team_1'],
            t2: (int) $data['team_2'],
            mode1: (string) ($data['mode_1'] ?? '') !== '' ? (string) $data['mode_1'] : null,
            mode2: (string) ($data['mode_2'] ?? '') !== '' ? (string) $data['mode_2'] : null,
            addedBy: $this->adminName(),
            runId: (int) $runId,
        );

        return response()->json([
            'ok' => true,
            'run_id' => (int) $runId,
            'redirect' => route('admin.stats-duel', ['run' => $runId]),
        ]);
    }

    /**
     * GET /admin/stats-duel/progress/{run} — état courant d'un run (AJAX).
     */
    public function progress(int $run): JsonResponse
    {
        Auth::requireAdmin();

        $row = DB::table('stats_duel_runs')->where('id', $run)->first();
        if ($row === null) {
            return response()->json(['ok' => false, 'error' => 'Run introuvable.'], 404);
        }

        return response()->json([
            'ok' => true,
            'status' => (int) $row->status,
            'progress' => (int) $row->progress,
            'message' => (string) ($row->message ?? ''),
        ]);
    }

    /**
     * GET /admin/stats-duel/result/{run} — HTML des deux équipes une fois le
     * calcul terminé (renvoie le rendu des partials pour injection AJAX).
     */
    public function result(int $run): JsonResponse
    {
        Auth::requireAdmin();

        $row = DB::table('stats_duel_runs')->where('id', $run)->first();
        if ($row === null) {
            return response()->json(['ok' => false, 'error' => 'Run introuvable.'], 404);
        }
        if ((int) $row->status !== 2) {
            return response()->json(['ok' => false, 'error' => 'Calcul pas encore terminé.'], 409);
        }

        $result = json_decode((string) $row->result, true) ?? ['teamA' => null, 'teamB' => null];

        $html = view('admin.partials.stats_duel_result', ['result' => $result])->render();

        return response()->json(['ok' => true, 'html' => $html]);
    }

    /**
     * POST /admin/stats-duel/run — valide les deux IDs puis recharte avec la
     * requête GET (qui déclenche le chargement des données).
     */
    public function run(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'team_1' => ['required', 'integer', 'min:1'],
            'team_2' => ['required', 'integer', 'min:1'],
            'mode_1' => ['nullable', 'in:,9v9,6s'],
            'mode_2' => ['nullable', 'in:,9v9,6s'],
        ]);

        return redirect()->route('admin.stats-duel', [
            't1' => (int) $data['team_1'],
            't2' => (int) $data['team_2'],
            'mode1' => (string) ($data['mode_1'] ?? ''),
            'mode2' => (string) ($data['mode_2'] ?? ''),
        ]);
    }

    /**
     * POST /admin/stats-duel/attach-log — colle manuellement un logs.tf sur un match.
     */
    public function attachLog(Request $request): RedirectResponse
    {
        Auth::requireAdmin();
        set_time_limit(300);

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'match_id' => ['required', 'integer', 'min:1'],
            'log_url' => ['required', 'string', 'max:255'],
        ]);

        $mode = $this->service->modeFor((int) $data['team_id'], $this->requestedMode($request));
        $out = $this->service->attachManual((string) $data['log_url'], (int) $data['match_id'], (int) $data['team_id'], $mode, $this->adminName());

        return back()->with($out['ok'] ? 'success' : 'error', $out['message']);
    }

    /**
     * POST /admin/stats-duel/detach-log — retire le lien match -> log.
     */
    public function detachLog(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate(['log_id' => ['required', 'integer', 'min:1']]);

        (new OfficialMatchRepository)->detachLog((int) $data['log_id']);

        return back()->with('success', 'Log officiel détaché du match.');
    }

    /**
     * POST /admin/stats-duel/scrape — scrape les logs manquants de l'équipe
     * (ou d'un seul match si match_id renseigné).
     */
    public function scrape(Request $request): RedirectResponse
    {
        Auth::requireAdmin();
        set_time_limit(300);

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'match_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $mode = $this->service->modeFor((int) $data['team_id'], $this->requestedMode($request));
        $matchId = isset($data['match_id']) ? (int) $data['match_id'] : null;

        $result = $this->service->scrapeMissing((int) $data['team_id'], $mode, $matchId, $this->adminName());

        $message = $result['attached'] > 0
            ? "{$result['attached']} log(s) rattaché(s) (".$result['checked'].' match(s) vérifié(s)).'
            : 'Aucun log trouvé pour '.$result['checked'].' match(s) vérifié(s).';

        return back()->with('success', $message);
    }

    /**
     * POST /admin/stats-duel/recompute — traite les logs non intégrés aux stats.
     */
    public function recompute(Request $request): RedirectResponse
    {
        Auth::requireAdmin();
        set_time_limit(300);

        $data = $request->validate(['team_id' => ['required', 'integer', 'min:1']]);

        $mode = $this->service->modeFor((int) $data['team_id'], $this->requestedMode($request));
        $result = $this->service->recompute((int) $data['team_id'], $mode);

        return back()->with('success', 'Recalcul : '.$result['processed'].' log(s) traité(s), '.$result['skipped'].' ignoré(s), '.$result['errors'].' en échec.');
    }

    /**
     * POST /admin/stats-duel/refresh-history — force le refetch de l'historique.
     */
    public function refreshHistory(Request $request): RedirectResponse
    {
        Auth::requireAdmin();
        set_time_limit(600);

        $data = $request->validate(['team_id' => ['required', 'integer', 'min:1']]);

        $result = $this->service->refreshHistory((int) $data['team_id']);

        return back()->with('success', 'Historique ETF2L rafraîchi pour '.$result['players'].' joueur(s) ('.$result['fetched'].' match(s) stocké(s)).');
    }

    /**
     * POST /admin/stats-duel/toggle-player — visibilité d'un joueur pour l'équipe.
     */
    public function togglePlayer(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'steamid' => ['required', 'string', 'max:32'],
            'visible' => ['required', 'boolean'],
        ]);

        $repo = new OfficialMatchRepository;
        $repo->setLineup((string) $data['steamid'], (int) $data['team_id'], (bool) $data['visible']);

        return response()->json(['ok' => true, 'visible' => (bool) $data['visible']]);
    }

    /**
     * POST /admin/stats-duel/blacklist-log.
     */
    public function blacklistLog(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'log_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        (new MatchLogRepository)->blacklist((int) $data['log_id'], $data['reason'] ?? null, $this->adminName());

        return back()->with('success', "Log {$data['log_id']} blacklisté (exclu des stats).");
    }

    /**
     * POST /admin/stats-duel/unblacklist-log.
     */
    public function unblacklistLog(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate(['log_id' => ['required', 'integer', 'min:1']]);

        (new MatchLogRepository)->unblacklist((int) $data['log_id']);

        return back()->with('success', 'Log retiré de la blacklist.');
    }

    /**
     * POST /admin/stats-duel/blacklist-team.
     */
    public function blacklistTeam(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (! DB::table('etf2l_teams')->where('team_id', (int) $data['team_id'])->exists()) {
            return back()->with('error', "Équipe ETF2L {$data['team_id']} introuvable.");
        }

        (new OfficialMatchRepository)->blacklistTeam((int) $data['team_id'], $data['reason'] ?? '', $this->adminName());

        return back()->with('success', "Équipe {$data['team_id']} blacklistée : ses logs ne comptent plus.");
    }

    /**
     * POST /admin/stats-duel/unblacklist-team.
     */
    public function unblacklistTeam(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate(['team_id' => ['required', 'integer', 'min:1']]);

        (new OfficialMatchRepository)->unblacklistTeam((int) $data['team_id']);

        return back()->with('success', 'Équipe retirée de la blacklist.');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function requestedMode(Request $request): ?string
    {
        $mode = (string) $request->input('mode', '');

        return $mode !== '' ? $mode : null;
    }

    private function adminName(): string
    {
        $user = Auth::user();

        return $user !== null
            ? (string) ($user->display_name ?? $user->name ?? 'admin')
            : 'admin';
    }

    /**
     * @return array<int, array{team_id: int, name: string}>
     */
    private function teamOptions(): array
    {
        $blacklisted = (new OfficialMatchRepository)->blacklistedTeamIds();

        return DB::table('etf2l_teams')
            ->orderBy('name')
            ->get()
            ->reject(static fn ($row): bool => in_array((int) $row->team_id, $blacklisted, true))
            ->map(static fn ($row): array => ['team_id' => (int) $row->team_id, 'name' => (string) $row->name])
            ->all();
    }

    /**
     * @return array<int, array{team_id: int, name: string, reason: string}>
     */
    private function blacklistedTeams(): array
    {
        return DB::table('team_blacklist')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($row): array {
                $name = DB::table('etf2l_teams')->where('team_id', (int) $row->team_id)->value('name');

                return [
                    'team_id' => (int) $row->team_id,
                    'name' => $name !== null ? (string) $name : 'Équipe #'.(int) $row->team_id,
                    'reason' => (string) ($row->reason ?? ''),
                ];
            })
            ->all();
    }
}
