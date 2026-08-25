<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRepository;
use App\Models\MatchLogRepository;
use App\Services\Auth;
use App\Services\SteamId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Endpoints JSON réservés aux administrateurs.
 * En cas de soumission de formulaire (non-AJAX), réponse via flash + redirection.
 */
final class AdminApiController extends Controller
{
    /**
     * Ajoute / retire un log de la blacklist (Match Stats).
     * POST /api/admin/blacklist  { action: add|remove, log_id, reason? }
     */
    public function blacklist(Request $request): JsonResponse|RedirectResponse
    {
        Auth::requireAdmin();

        $action = (string) $request->input('action', '');
        $logId = (string) $request->input('log_id', '');
        $reason = trim((string) $request->input('reason', ''));

        if (! ctype_digit($logId)) {
            return $this->respond($request, false, 'ID de log invalide.');
        }

        $repo = new MatchLogRepository();
        $logId = (int) $logId;

        if ($action === 'add') {
            $added = $repo->blacklist($logId, $reason !== '' ? $reason : null, (string) Auth::steamId64());
            if ($added) {
                $repo->invalidateLogsCache();
            }

            return $this->respond(
                $request,
                $added,
                $added ? "Le log #$logId a été blacklisté avec succès." : "Le log #$logId est déjà blacklisté.",
            );
        }

        if ($action === 'remove') {
            $removed = $repo->unblacklist($logId);
            if ($removed) {
                $repo->invalidateLogsCache();
            }

            return $this->respond(
                $request,
                $removed,
                $removed ? "Le log #$logId a été retiré de la blacklist." : "Le log #$logId n'est pas dans la blacklist.",
            );
        }

        return $this->respond($request, false, 'Action non reconnue.');
    }

    /**
     * Change le mode (6s/9v9) d'un log en base.
     * POST /api/admin/match-mode  { action: switch_mode, log_id, mode }
     */
    public function matchMode(Request $request): JsonResponse|RedirectResponse
    {
        Auth::requireAdmin();

        $action = (string) $request->input('action', '');
        $logId = (string) $request->input('log_id', '');
        $mode = strtolower(trim((string) $request->input('mode', '')));

        if (! ctype_digit($logId)) {
            return $this->respond($request, false, 'ID de log invalide.');
        }
        if (! in_array($mode, ['6s', '9v9'], true)) {
            return $this->respond($request, false, 'Mode de jeu invalide (6s ou 9v9 attendu).');
        }
        if ($action !== 'switch_mode') {
            return $this->respond($request, false, 'Action non reconnue.');
        }

        $result = (new AdminRepository())->switchMatchMode((int) $logId, $mode);

        return $this->respond($request, $result['success'], $result['message']);
    }

    /**
     * Mise à jour globale du profil d'un joueur (pseudo, pays, rôles, verrous).
     * POST /api/admin/player-update
     */
    public function playerUpdate(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $targetSteamid = (string) $request->input('target_steamid', '');

        if ($targetSteamid === '' || ! preg_match('/^\d{17}$/', $targetSteamid)) {
            return redirect('/admin/dashboard')->with('error', 'Erreur : SteamID64 invalide.');
        }

        $displayName = trim((string) $request->input('display_name', ''));
        $country = strtolower(trim((string) $request->input('country', 'unknown')));

        if ($displayName === '') {
            return redirect('/admin/manage-player/' . urlencode($targetSteamid))
                ->with('error', "Le pseudo d'affichage ne peut pas être vide.");
        }

        $steamid3 = SteamId::toSteamId3($targetSteamid);

        try {
            $updated = (new AdminRepository())->updatePlayer(
                steamid3: $steamid3,
                displayName: $displayName,
                country: $country,
                isFounder: $request->has('is_founder') ? 1 : 0,
                isModerator: $request->has('is_moderator') ? 1 : 0,
                isMentor: $request->has('is_mentor') ? 1 : 0,
                isMixer: $request->has('is_mixer') ? 1 : 0,
                resetNameChange: $request->has('reset_name_change'),
                resetCountryChange: $request->has('reset_country_change'),
            );

            if ($updated) {
                return redirect('/admin/manage-player/' . urlencode($targetSteamid))
                    ->with('success', 'Le profil de ' . htmlspecialchars($displayName) . ' a été mis à jour avec succès !');
            }

            return redirect('/admin/manage-player/' . urlencode($targetSteamid))
                ->with('error', "Le joueur est introuvable ou aucune modification n'a été détectée.");
        } catch (\PDOException $e) {
            return redirect('/admin/manage-player/' . urlencode($targetSteamid))
                ->with('error', "Erreur BDD lors de l'enregistrement : " . $e->getMessage());
        }
    }

    /**
     * Répond en JSON pour les requêtes AJAX, sinon flash + redirection (formulaires).
     */
    private function respond(Request $request, bool $success, string $message): JsonResponse|RedirectResponse
    {
        if ($request->ajax()) {
            return response()->json(['success' => $success, 'message' => $message]);
        }

        return redirect('/admin/manage-blacklist')->with($success ? 'success' : 'error', $message);
    }
}
