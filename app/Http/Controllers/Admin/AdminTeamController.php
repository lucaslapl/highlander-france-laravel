<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManagedTeamsRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\ManagedTeamImportService;
use App\Services\ManagedTeamService;
use App\Services\SteamId;
use App\Services\TeamStatsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gestion admin des équipes françaises mises en avant (managed_teams) :
 * import depuis ETF2L, activation, division, logo, roster (membres, leaders).
 *
 * Le roster peut être importé de l'API ETF2L ou complété à la main ; les
 * leaders sont désignés ici (admin uniquement), jamais retirés par une
 * ré-synchronisation.
 */
final class AdminTeamController extends Controller
{
    private const MAX_LOGO_KB = 2048;

    private ManagedTeamsRepository $teams;

    public function __construct()
    {
        $this->teams = new ManagedTeamsRepository;
    }

    /**
     * GET /admin/equipes — liste des équipes gérées.
     */
    public function index(): View
    {
        Auth::requireAdmin();

        return view('admin.teams', [
            'title' => 'Admin - Équipes FR',
            'description' => 'Import et gestion des équipes françaises mises en avant sur le site.',
            'styles' => ['/_css/admin.css'],
            'scripts' => [],
            'teams' => $this->teams->all(),
            'divisions' => config('hlfr.team_divisions', []),
        ]);
    }

    /**
     * GET /admin/equipes/search?q= — autocomplete d'équipes ETF2L locales
     * (table etf2l_teams) pour l'import.
     */
    public function search(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $results = DB::table('etf2l_teams')
            ->where(function ($builder) use ($query): void {
                $builder->where('name', 'like', '%'.$query.'%')
                    ->orWhere('tag', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['team_id', 'name', 'tag', 'country'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->team_id,
                'name' => (string) $row->name,
                'tag' => $row->tag !== null ? (string) $row->tag : null,
                'country' => $row->country !== null ? (string) $row->country : null,
            ])
            ->all();

        return response()->json(array_values($results));
    }

    /**
     * POST /admin/equipes/store — importe une équipe depuis son id ETF2L que
     * celle-ci soit déjà en base ou inconnue (récupérée à la volée via l'API).
     */
    public function store(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'etf2l_team_id' => ['required', 'integer', 'min:1', 'max:9999999'],
        ]);

        $service = new ManagedTeamImportService(new TeamStatsService, $this->teams);

        // Le roster/meta sont déjà en cache local pour les équipes connues :
        // on garde une seule implémentation d'import (API) — la requête en base
        // ne sert qu'à l'autocomplete.
        $result = $service->import((int) $data['etf2l_team_id']);

        if (! $result['ok']) {
            return back()->with('error', $result['error'] ?? 'Import impossible.');
        }

        $team = $result['team'];

        return redirect('/admin/equipes/'.(int) $team['id'])->with(
            'success',
            'Équipe importée sur le site. Activez-la une fois le roster validé.'
        );
    }

    /**
     * GET /admin/equipes/{id} — gestion complète d'une équipe.
     */
    public function show(int $id): View
    {
        Auth::requireAdmin();

        $team = $this->teams->find($id);
        if ($team === null) {
            abort(404);
        }

        $team['logo_url'] = ManagedTeamsRepository::logoUrl($team['logo_path'] ?? null);

        return view('admin.team_manage', [
            'title' => 'Admin - Équipe '.$team['name'],
            'description' => 'Gestion du roster, des leaders et de la présentation d\'une équipe.',
            'styles' => ['/_css/admin.css'],
            'scripts' => [],
            'team' => $team,
            'members' => $this->teams->members((int) $team['id']),
            'divisions' => config('hlfr.team_divisions', []),
            'classes' => config('hlfr.tf2_classes', []),
            'divisionLabels' => config('hlfr.team_divisions', []),
        ]);
    }

    /**
     * POST /admin/equipes/{id}/update — champs éditoriaux + activation.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        Auth::requireAdmin();

        $team = $this->teams->find($id);
        if ($team === null) {
            abort(404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'max:64'],
            'division' => ['nullable', Rule::in(array_keys(config('hlfr.team_divisions', [])))],
            'slogan' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $name = (string) $data['name'];
        $tag = $data['tag'] ?? null;
        $country = $data['country'] ?? null;
        $division = $data['division'] ?? null;
        $slogan = $data['slogan'] ?? null;
        $description = $data['description'] ?? null;

        $this->teams->update($id, [
            'name' => mb_substr($name, 0, 255),
            'tag' => $tag !== null && (string) $tag !== '' ? mb_substr((string) $tag, 0, 64) : null,
            'country' => $country !== null && (string) $country !== '' ? mb_substr((string) $country, 0, 64) : null,
            'division' => $division !== null && (string) $division !== '' ? (string) $division : null,
            'slogan' => $slogan !== null && (string) $slogan !== '' ? mb_substr((string) $slogan, 0, 160) : null,
            'description' => $description !== null && (string) $description !== '' ? (string) $description : null,
            'is_active' => ! empty($data['is_active']) && $data['is_active'] !== '0',
            'updated_at' => now(),
        ]);

        AdminLogger::log('admin_teams.php', null, 'SUCCESS: mise à jour équipe #'.$id);

        return back()->with('success', 'Équipe mise à jour.');
    }

    /**
     * POST /admin/equipes/{id}/resync — re-synchronise le roster depuis ETF2L.
     */
    public function resync(Request $request, int $id): RedirectResponse
    {
        Auth::requireAdmin();

        $service = new ManagedTeamImportService(new TeamStatsService, $this->teams);
        $result = $service->syncRoster($id);

        if (! $result['ok']) {
            return back()->with('error', $result['error'] ?? 'Synchronisation impossible.');
        }

        $msg = 'Roster synchronisé ('.(int) $result['created'].' ajouté(s), '.(int) $result['updated'].' à jour).';
        if ((int) $result['departed'] > 0) {
            $msg .= ' '.$result['departed'].' joueur(s) ETF2L ne font plus partie du roster (conservés, à retirer manuellement si besoin).';
        }

        return back()->with('success', $msg);
    }

    /**
     * POST /admin/equipes/{id}/logo — upload/remplacement du logo (max 2 Mo).
     */
    public function logo(Request $request, int $id): RedirectResponse
    {
        Auth::requireAdmin();

        $team = $this->teams->find($id);
        if ($team === null) {
            abort(404);
        }

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:'.self::MAX_LOGO_KB],
        ]);

        $service = new ManagedTeamService($this->teams);
        if (! $service->saveLogo($team, $request->file('logo'))) {
            return back()->with('error', 'Format de logo non autorisé (jpeg, png, webp).');
        }

        return back()->with('success', 'Logo mis à jour.');
    }

    /**
     * POST /admin/equipes/{id}/logo/delete — supprime le logo.
     */
    public function logoDelete(Request $request, int $id): RedirectResponse
    {
        Auth::requireAdmin();

        $team = $this->teams->find($id);
        if ($team === null) {
            abort(404);
        }

        (new ManagedTeamService($this->teams))->deleteLogo($team);

        return back()->with('success', 'Logo supprimé.');
    }

    /**
     * POST /admin/equipes/{id}/toggle — activation/désactivation.
     */
    public function toggle(Request $request, int $id): RedirectResponse
    {
        Auth::requireAdmin();

        $team = $this->teams->find($id);
        if ($team === null) {
            abort(404);
        }

        $this->teams->update($id, ['is_active' => ! (int) $team['is_active'], 'updated_at' => now()]);

        return back()->with('success', (int) $team['is_active'] ? 'Équipe masquée.' : 'Équipe active sur le site.');
    }

    /**
     * POST /admin/equipes/{id}/delete — suppression de l'équipe (et de son logo).
     */
    public function delete(Request $request, int $id): RedirectResponse
    {
        Auth::requireAdmin();

        $team = $this->teams->find($id);
        if ($team === null) {
            abort(404);
        }

        (new ManagedTeamService($this->teams))->deleteLogo($team);
        $this->teams->delete($id);

        return redirect('/admin/equipes')->with('success', 'Équipe supprimée.');
    }

    // ─── Roster ────────────────────────────────────────────────────────────────

    /**
     * POST /admin/equipes/{id}/members/add — ajoute un membre au roster
     * (par SteamID64, avec pré-remplissage si le joueur est inscrit sur le site).
     */
    public function memberAdd(Request $request, int $id): RedirectResponse
    {
        Auth::requireAdmin();

        $team = $this->teams->find($id);
        if ($team === null) {
            abort(404);
        }

        $data = $request->validate([
            'steamid64' => ['required', 'regex:/^\d{17}$/'],
            'class' => ['nullable', Rule::in(array_keys(config('hlfr.tf2_classes', [])))],
            'status' => ['sometimes', Rule::in(['starter', 'backup'])],
            'is_leader' => ['sometimes', 'boolean'],
        ]);

        $steamid64 = (string) $data['steamid64'];
        $steamid3 = SteamId::toSteamId3($steamid64);

        $site = $steamid3 !== null
            ? DB::table('players_info')->where('steamid', $steamid3)->first()
            : null;

        $memberId = $this->teams->addMember($id, [
            'steamid64' => $steamid64,
            'steam_name' => $site !== null
                ? (($site->display_name ?? '') !== '' ? (string) $site->display_name : (string) ($site->name ?? ''))
                : null,
            'country' => $site !== null && $site->country !== null ? mb_substr((string) $site->country, 0, 64) : null,
            'class' => $data['class'] ?? null,
            'status' => $data['status'] ?? 'starter',
            'source' => 'manual',
        ]);

        if ($memberId === null) {
            return back()->with('error', 'Ce joueur est déjà membre du roster ou le SteamID64 est invalide.');
        }

        if (! empty($data['is_leader']) && $data['is_leader'] !== '0') {
            $this->teams->setLeader($id, $memberId, true);
        }

        return back()->with('success', 'Membre ajouté au roster.');
    }

    /**
     * POST /admin/equipes/{id}/members/{memberId}/update — classe et statut.
     */
    public function memberUpdate(Request $request, int $id, int $memberId): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'class' => ['nullable', Rule::in(array_keys(config('hlfr.tf2_classes', [])))],
            'status' => ['sometimes', Rule::in(['starter', 'backup'])],
        ]);

        $class = $data['class'] ?? null;
        $this->teams->updateMember($id, $memberId, [
            'class' => $class !== null && (string) $class !== '' ? (string) $class : null,
            'status' => $data['status'] ?? 'starter',
        ]);

        return back()->with('success', 'Membre mis à jour.');
    }

    /**
     * POST /admin/equipes/{id}/members/{memberId}/leader — promotion/destitution
     * en leader (admin uniquement).
     */
    public function memberLeader(Request $request, int $id, int $memberId): RedirectResponse
    {
        Auth::requireAdmin();

        $member = DB::table('managed_team_members')->where('team_id', $id)->where('id', $memberId)->first();
        if ($member === null) {
            return back()->with('error', 'Membre introuvable.');
        }

        $this->teams->setLeader($id, $memberId, ! (int) $member->is_leader);

        return back()->with('success', (int) $member->is_leader ? 'Leader retiré.' : 'Membre promu leader.');
    }

    /**
     * POST /admin/equipes/{id}/members/{memberId}/remove
     */
    public function memberRemove(Request $request, int $id, int $memberId): RedirectResponse
    {
        Auth::requireAdmin();

        $this->teams->removeMember($id, $memberId);

        return back()->with('success', 'Membre retiré du roster.');
    }
}
