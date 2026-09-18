<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ManagedTeamsRepository;
use App\Services\Auth;
use App\Services\ManagedTeamService;
use App\Services\SteamId;
use App\Services\TeamStatsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pages publiques des équipes françaises (/equipes) : listing par division,
 * page équipe (roster, description, derniers matchs ETF2L) et espace d'édition
 * réservé aux leaders du roster (ou aux admins) : slogan, description, logo,
 * classe/statut des membres, ajout/retrait de joueurs.
 */
final class ManagedTeamController extends Controller
{
    private ManagedTeamsRepository $teams;

    private ManagedTeamService $service;

    public function __construct()
    {
        $this->teams = new ManagedTeamsRepository;
        $this->service = new ManagedTeamService($this->teams);
    }

    /**
     * GET /equipes — toutes les équipes actives, groupées par division.
     */
    public function index(): View
    {
        $teams = $this->teams->activeTeams();

        return view('pages.teams', [
            'title' => 'Équipes françaises Highlander - Highlander France',
            'description' => 'Les équipes françaises actives en compétitif 9v9, leurs rosters et leurs derniers résultats.',
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'Équipes', 'url' => site_url().'/equipes'],
            ],
            'teams' => $teams,
            'divisions' => config('hlfr.team_divisions', []),
        ]);
    }

    /**
     * GET /equipes/{slug} — fiche d'une équipe.
     */
    public function show(string $slug, TeamStatsService $stats): View
    {
        $team = $this->teams->findBySlug($slug);
        if ($team === null || ! (int) $team['is_active']) {
            abort(404);
        }

        $team = $this->service->present($team);
        $members = $this->teams->members((int) $team['id']);

        $recent = [];
        try {
            $recent = $stats->fetchRecentResults((int) $team['etf2l_team_id'], 5);
        } catch (\Throwable) {
            $recent = [];
        }

        if ($recent !== []) {
            $localIds = $this->teams->existingEtf2lMatchIds(array_column($recent, 'match_id'));
            foreach ($recent as &$match) {
                $match['link_url'] = in_array((int) $match['match_id'], $localIds, true)
                    ? '/match/'.(int) $match['match_id']
                    : 'https://etf2l.org/matches/'.(int) $match['match_id'].'/';
                $match['link_external'] = ! in_array((int) $match['match_id'], $localIds, true);
            }
            unset($match);
        }

        return view('pages.team_show', [
            'title' => $team['name'].' - Équipe Highlander France',
            'description' => $this->metaDescription($team),
            'og_image' => $team['logo_url'] ?? null,
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'Équipes', 'url' => site_url().'/equipes'],
                ['name' => $team['name'], 'url' => site_url().'/equipes/'.$team['slug']],
            ],
            'team' => $team,
            'members' => $members,
            'recent' => $recent,
            'canEdit' => $this->service->canEdit($team),
        ]);
    }

    /**
     * GET /equipes/{slug}/editer — espace d'édition du leader.
     */
    public function edit(string $slug): View
    {
        $team = $this->teams->findBySlug($slug);
        if ($team === null || ! (int) $team['is_active']) {
            abort(404);
        }

        if (! $this->service->canEdit($team)) {
            abort(403, 'Vous devez être leader de cette équipe pour l\'éditer.');
        }

        return view('pages.team_edit', [
            'title' => 'Éditer '.$team['name'].' - Highlander France',
            'description' => 'Gestion de la page de l\'équipe : slogan, description, logo et roster.',
            'team' => $this->service->present($team),
            'members' => $this->teams->members((int) $team['id']),
            'classes' => config('hlfr.tf2_classes', []),
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    /**
     * POST /equipes/{slug}/editer — slogan + description (leader).
     */
    public function update(Request $request, string $slug): RedirectResponse
    {
        $team = $this->requireEditable($slug);

        $data = $request->validate([
            'slogan' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:20000'],
        ]);

        $slogan = $data['slogan'] ?? null;
        $description = $data['description'] ?? null;

        $this->teams->update((int) $team['id'], [
            'slogan' => $slogan !== null && (string) $slogan !== '' ? mb_substr((string) $slogan, 0, 160) : null,
            'description' => $description !== null && (string) $description !== '' ? (string) $description : null,
            'updated_at' => now(),
        ]);

        return redirect('/equipes/'.$team['slug'].'/editer')->with('success', 'Présentation enregistrée.');
    }

    /**
     * POST /equipes/{slug}/logo — upload du logo (leader).
     */
    public function logo(Request $request, string $slug): RedirectResponse
    {
        $team = $this->requireEditable($slug);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        if (! $this->service->saveLogo($team, $request->file('logo'))) {
            return back()->with('error', 'Format de logo non autorisé (jpeg, png, webp).');
        }

        return back()->with('success', 'Logo mis à jour.');
    }

    /**
     * POST /equipes/{slug}/logo/supprimer — suppression du logo (leader).
     */
    public function logoDelete(Request $request, string $slug): RedirectResponse
    {
        $team = $this->requireEditable($slug);
        $this->service->deleteLogo($team);

        return back()->with('success', 'Logo supprimé.');
    }

    /**
     * POST /equipes/{slug}/membres/ajouter — ajout d'un joueur au roster.
     */
    public function memberAdd(Request $request, string $slug): RedirectResponse
    {
        $team = $this->requireEditable($slug);

        $data = $request->validate([
            'steamid64' => ['required', 'regex:/^\d{17}$/'],
            'class' => ['nullable', Rule::in(array_keys(config('hlfr.tf2_classes', [])))],
            'status' => ['sometimes', Rule::in(['starter', 'backup'])],
        ]);

        $steamid64 = (string) $data['steamid64'];
        $steamid3 = SteamId::toSteamId3($steamid64);
        $site = DB::table('players_info')->where('steamid', $steamid3)->first();

        $memberId = $this->teams->addMember((int) $team['id'], [
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

        return back()->with('success', 'Joueur ajouté au roster.');
    }

    /**
     * POST /equipes/{slug}/membres/{memberId}/modifier — classe + statut.
     */
    public function memberUpdate(Request $request, string $slug, int $memberId): RedirectResponse
    {
        $team = $this->requireEditable($slug);

        $data = $request->validate([
            'class' => ['nullable', Rule::in(array_keys(config('hlfr.tf2_classes', [])))],
            'status' => ['sometimes', Rule::in(['starter', 'backup'])],
        ]);

        $class = $data['class'] ?? null;
        $this->teams->updateMember((int) $team['id'], $memberId, [
            'class' => $class !== null && (string) $class !== '' ? (string) $class : null,
            'status' => $data['status'] ?? 'starter',
        ]);

        return back()->with('success', 'Membre mis à jour.');
    }

    /**
     * POST /equipes/{slug}/membres/{memberId}/retirer — retrait du roster.
     */
    public function memberRemove(Request $request, string $slug, int $memberId): RedirectResponse
    {
        $team = $this->requireEditable($slug);
        $this->teams->removeMember((int) $team['id'], $memberId);

        return back()->with('success', 'Joueur retiré du roster.');
    }

    /**
     * Récupère l'équipe active et vérifie les droits d'édition (leader/admin).
     *
     * @return array<string, mixed>
     */
    private function requireEditable(string $slug): array
    {
        $team = $this->teams->findBySlug($slug);
        if ($team === null || ! (int) $team['is_active']) {
            abort(404);
        }

        if (! $this->service->canEdit($team)) {
            abort(403, 'Vous devez être leader de cette équipe pour l\'éditer.');
        }

        return $team;
    }

    private function metaDescription(array $team): string
    {
        $slogan = trim((string) ($team['slogan'] ?? ''));
        if ($slogan !== '') {
            return mb_substr($slogan, 0, 155);
        }

        return 'Roster, description et derniers résultats de l\'équipe '.$team['name'].' sur Highlander France.';
    }
}
