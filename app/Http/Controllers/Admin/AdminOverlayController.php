<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OverlayRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\OverlayStatsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Outil « Overlay Logs » : génération d'overlays de stats pour OBS
 * Studio à partir d'un log logs.tf. Accessible aux admins et aux rôles
 * caster / prod (hub des overlays /admin/overlays-stream).
 *
 * Chaque overlay possède son propre token et son URL publique
 * (/overlay/{token}) à pointer dans OBS via une source navigateur web.
 * Les équipes sont présentées comme A / B (logs.tf les classe en
 * rouge/bleu de façon arbitraire, sans lien avec le layout du broadcast :
 * le bouton d'interversion de la page d'édition échange noms et avatars).
 * Les noms d'équipes et avatars (URL externe, mémorisée pour réutilisation)
 * sont personnalisables ; la vue overlay se rafraîchit automatiquement
 * dès que le payload change (polling de version côté navigateur).
 */
final class AdminOverlayController extends Controller
{
    private const MAX_NAME_LEN = 64;

    private OverlayRepository $overlays;

    private OverlayStatsService $stats;

    public function __construct()
    {
        $this->overlays = new OverlayRepository;
        $this->stats = new OverlayStatsService;
    }

    /**
     * GET /admin/overlay — liste des overlays + formulaire de génération.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.overlay', [
            'title' => 'Admin - Overlay Logs (OBS)',
            'description' => 'Génération d\'overlays de stats logs.tf pour les broadcasts OBS de Highlander France.',
            'overlays' => $this->overlays->all(),
            'memorized_avatars' => $this->overlays->memorizedAvatars(),
        ]);
    }

    /**
     * POST /admin/overlay/generate — extrait l'ID du log depuis une URL ou
     * un identifiant saisi, récupère les stats logs.tf et crée l'overlay.
     * Les noms d'équipes et avatars par URL saisis dès la génération sont
     * appliqués au payload, pour préparer l'overlay en amont du stream.
     */
    public function generate(Request $request): RedirectResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'log' => ['required', 'string', 'max:255'],
            'red_name' => ['nullable', 'string', 'max:'.self::MAX_NAME_LEN],
            'blue_name' => ['nullable', 'string', 'max:'.self::MAX_NAME_LEN],
            'red_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'blue_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
        ]);

        $logId = $this->parseLogId((string) $data['log']);
        if ($logId === null) {
            return back()->with('error', 'Lien logs.tf invalide. Formats acceptés : https://logs.tf/12345678, https://logs.tf/#12345678 ou 12345678.');
        }

        $payload = $this->stats->buildPayload($logId);
        if ($payload === null) {
            return back()->with('error', "Log logs.tf #$logId introuvable ou sans données de joueurs.");
        }

        $token = bin2hex(random_bytes(8));
        $overlay = array_merge($payload, [
            'token' => $token,
            'created_at' => time(),
            'teams' => $this->withGenerationInputs($payload['teams'], $data),
        ]);

        $this->overlays->save($overlay);
        AdminLogger::log('admin_overlay_generate', null, 'SUCCESS (overlay '.$token.' depuis logs.tf #'.$logId.')');

        return redirect('/admin/overlay/'.$token)->with('success', "Overlay créé pour le log logs.tf #$logId.");
    }

    /**
     * GET /admin/overlay/{token} — édition d'un overlay (équipes A / B).
     */
    public function edit(string $token): View
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        return view('admin.overlay_edit', [
            'title' => 'Admin - Overlay Logs (OBS)',
            'description' => 'Personnalisation de l\'overlay de stats pour les broadcasts OBS.',
            'overlay' => $overlay,
            'overlay_url' => url('/overlay/'.$overlay['token']),
            'memorized_avatars' => $this->overlays->memorizedAvatars(),
        ]);
    }

    /**
     * POST /admin/overlay/{token}/update — noms des équipes A / B et
     * avatars par URL externe (mémorisée pour réutilisation).
     */
    public function update(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        $data = $request->validate([
            'red_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'blue_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'red_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'blue_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
        ]);

        $overlay['teams']['red']['name'] = trim((string) $data['red_name']);
        $overlay['teams']['blue']['name'] = trim((string) $data['blue_name']);
        $overlay['teams']['red']['avatar_url'] = $this->cleanUrl($data['red_avatar_url'] ?? null);
        $overlay['teams']['blue']['avatar_url'] = $this->cleanUrl($data['blue_avatar_url'] ?? null);

        $this->overlays->save($overlay);
        $this->rememberAvatars($overlay['teams']);

        return back()->with('success', 'Overlay mis à jour.');
    }

    /**
     * POST /admin/overlay/{token}/swap — intervertit les équipes A et B
     * côté personnalisation : noms et avatars par URL (ainsi que les
     * avatars historiques uploadés, s'il en reste). Les scores et les
     * stats des joueurs ne bougent pas : logs.tf classe les équipes en
     * rouge/bleu arbitrairement, ce bouton sert à aligner l'affichage
     * sur le layout souhaité du broadcast.
     */
    public function swap(string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        $redName = (string) $overlay['teams']['red']['name'];
        $redAvatarUrl = $overlay['teams']['red']['avatar_url'] ?? null;

        $overlay['teams']['red']['name'] = (string) $overlay['teams']['blue']['name'];
        $overlay['teams']['red']['avatar_url'] = $overlay['teams']['blue']['avatar_url'] ?? null;
        $overlay['teams']['blue']['name'] = $redName;
        $overlay['teams']['blue']['avatar_url'] = $redAvatarUrl;

        $this->overlays->save($overlay);
        $this->overlays->swapAvatars($token);
        AdminLogger::log('admin_overlay_swap', null, 'SUCCESS (overlay '.$token.')');

        return back()->with('success', 'Équipes interverties : noms et avatars A / B échangés.');
    }

    /**
     * POST /admin/overlay/{token}/refresh — relit le log logs.tf (scores et
     * stats à jour, ex. fin de map) en conservant noms et avatars.
     */
    public function refresh(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        $payload = $this->stats->buildPayload((int) $overlay['log_id']);
        if ($payload === null) {
            return back()->with('error', 'Impossible de relire le log logs.tf, réessayez plus tard.');
        }

        // Les personnalisations (noms, avatars) priment sur le refresh.
        $overlay = array_merge($overlay, $payload, [
            'token' => $token,
            'teams' => $this->mergeTeams($overlay['teams'], $payload['teams']),
        ]);
        $this->overlays->save($overlay);

        return back()->with('success', 'Stats rafraîchies depuis logs.tf.');
    }

    /**
     * POST /admin/overlay/{token}/delete — supprime l'overlay.
     */
    public function delete(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $this->requireOverlay($token);
        $this->overlays->delete($token);

        return redirect('/admin/overlay')->with('success', 'Overlay supprimé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOverlay(string $token): array
    {
        $overlay = $this->overlays->find($token);
        if ($overlay === null) {
            abort(404);
        }

        return $overlay;
    }

    /**
     * Extrait l'ID d'un log depuis une URL logs.tf ou un identifiant brut.
     */
    private function parseLogId(string $input): ?int
    {
        $input = trim($input);

        if (preg_match('/^\d{1,10}$/', $input) === 1) {
            return (int) $input;
        }

        if (preg_match('~logs\.tf/(?:#/)?(\d{1,10})~i', $input, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Initialise les champs personnalisables des équipes depuis les saisies
     * du formulaire de génération (optionnelles) : noms et avatars par URL,
     * avec repli sur les valeurs par défaut du payload logs.tf. Chaque équipe
     * saisie (nom + URL d'avatar) est mémorisée pour les prochaines
     * générations, avec son visuel et son nom proposés en un clic.
     *
     * @param  array<string, array<string, mixed>>  $teams
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function withGenerationInputs(array $teams, array $data): array
    {
        foreach (['red', 'blue'] as $team) {
            $name = trim((string) ($data[$team.'_name'] ?? ''));
            $teams[$team]['name'] = $name !== '' ? $name : (string) $teams[$team]['name'];
            $teams[$team]['avatar_url'] = $this->cleanUrl($data[$team.'_avatar_url'] ?? null);
        }

        $this->rememberAvatars($teams);

        return $teams;
    }

    /**
     * Mémorise les URL d'avatars non vides des deux équipes.
     *
     * @param  array<string, array<string, mixed>>  $teams
     */
    private function rememberAvatars(array $teams): void
    {
        foreach (['red', 'blue'] as $team) {
            $url = (string) ($teams[$team]['avatar_url'] ?? '');
            if ($url !== '') {
                $this->overlays->rememberAvatar($url, (string) ($teams[$team]['name'] ?? ''));
            }
        }
    }

    /**
     * Conserve noms et avatars personnalisés, met à jour les scores.
     *
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $fresh
     * @return array<string, array<string, mixed>>
     */
    private function mergeTeams(array $current, array $fresh): array
    {
        foreach (['red', 'blue'] as $team) {
            $fresh[$team]['name'] = $current[$team]['name'] ?? $fresh[$team]['name'];
            $fresh[$team]['avatar_url'] = $current[$team]['avatar_url'] ?? null;
        }

        return $fresh;
    }

    /**
     * Normalise une URL d'avatar saisie (vide => null).
     */
    private function cleanUrl(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || ! Str::startsWith($value, ['http://', 'https://'])) {
            return null;
        }

        return $value;
    }
}
