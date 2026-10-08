<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth;
use App\Services\Etf2lTeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Équipes ETF2L partagées par tous les outils overlay (Logs, Scores,
 * Bracket, Pick/Ban) : endpoints JSON du remplissage assisté,
 * interrogés en JavaScript par admin_etf2l_teams.js — choisir une
 * compétition, charger ses équipes (nom, avatar, pays), puis piocher
 * dans chaque bloc équipe du formulaire ; le roster (SteamIDs des
 * joueurs) de l'équipe choisie peut être récupéré pour remplir les
 * champs de joueurs (séries) ou servir de référence d'identité aux
 * outils qui alignent les couleurs des logs (Overlay Logs, Scores).
 * Accessible aux admins et aux rôles caster / prod (middleware
 * overlay-tools), au même titre que les éditeurs qu'il alimente. Vide
 * si l'API est indisponible ou la compétition inconnue : la saisie
 * manuelle reste toujours possible.
 */
final class AdminEtf2lTeamsController extends Controller
{
    /**
     * GET /admin/overlay/etf2l/teams?competition_id=N — équipes d'une
     * compétition ETF2L pour le remplissage assisté des éditeurs
     * d'overlay, interrogé en JavaScript. Vide si l'API est indisponible
     * ou la compétition inconnue.
     */
    public function teams(Request $request): JsonResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'competition_id' => ['required', 'integer', 'min:1'],
        ]);

        $teams = [];
        try {
            $teams = (new Etf2lTeamService)->teams((int) $data['competition_id']);
        } catch (\Throwable) {
            // API indisponible : liste vide, message d'erreur explicite côté client.
        }

        return response()
            ->json(['teams' => $teams])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * GET /admin/overlay/etf2l/roster?team_id=N — roster ETF2L d'une
     * équipe (SteamID64 de ses joueurs) pour le remplissage assisté des
     * champs de joueurs, interrogé en JavaScript. Null si l'API est
     * indisponible ou l'équipe inconnue : la saisie manuelle reste
     * toujours possible.
     */
    public function roster(Request $request): JsonResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
        ]);

        $roster = null;
        try {
            $roster = (new Etf2lTeamService)->roster((int) $data['team_id']);
        } catch (\Throwable) {
            // API indisponible : roster null, message explicite côté client.
        }

        return response()
            ->json(['roster' => $roster])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
