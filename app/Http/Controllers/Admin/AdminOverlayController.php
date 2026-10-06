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
 * Outil admin « Overlay match » : génération d'overlays de stats pour OBS
 * Studio à partir d'un log logs.tf.
 *
 * Chaque overlay possède son propre token et son URL publique
 * (/overlay/{token}) à pointer dans OBS via une source navigateur web.
 * Les noms d'équipes, avatars (upload ou URL) et le style (opacité, blur)
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
        Auth::requireAdmin();

        return view('admin.overlay', [
            'title' => 'Admin - Overlay match (OBS)',
            'description' => 'Génération d\'overlays de stats logs.tf pour les broadcasts OBS de Highlander France.',
            'overlays' => $this->overlays->all(),
        ]);
    }

    /**
     * POST /admin/overlay/generate — extrait l'ID du log depuis une URL ou
     * un identifiant saisi, récupère les stats logs.tf et crée l'overlay.
     */
    public function generate(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'log' => ['required', 'string', 'max:255'],
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
            'teams' => $this->withAvatarDefaults($payload['teams']),
            'style' => $this->defaultStyle(),
        ]);

        $this->overlays->save($overlay);
        AdminLogger::log('admin_overlay_generate', null, 'SUCCESS (overlay '.$token.' depuis logs.tf #'.$logId.')');

        return redirect('/admin/overlay/'.$token)->with('success', "Overlay créé pour le log logs.tf #$logId.");
    }

    /**
     * GET /admin/overlay/{token} — édition d'un overlay (équipes, style).
     */
    public function edit(string $token): View
    {
        Auth::requireAdmin();

        $overlay = $this->requireOverlay($token);

        return view('admin.overlay_edit', [
            'title' => 'Admin - Overlay match (OBS)',
            'description' => 'Personnalisation de l\'overlay de stats pour les broadcasts OBS.',
            'overlay' => $overlay,
            'overlay_url' => url('/overlay/'.$overlay['token']),
            'has_avatar' => [
                'red' => $this->overlays->hasAvatar($overlay['token'], 'red'),
                'blue' => $this->overlays->hasAvatar($overlay['token'], 'blue'),
            ],
        ]);
    }

    /**
     * POST /admin/overlay/{token}/update — noms d'équipes, avatars (URL) et
     * style (panneau, opacité, blur).
     */
    public function update(Request $request, string $token): RedirectResponse
    {
        Auth::requireAdmin();

        $overlay = $this->requireOverlay($token);

        $data = $request->validate([
            'red_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'blue_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'red_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'blue_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'panel' => ['nullable', 'boolean'],
            'opacity' => ['required', 'integer', 'between:0,100'],
            'blur' => ['required', 'integer', 'between:0,20'],
        ]);

        $overlay['teams']['red']['name'] = trim((string) $data['red_name']);
        $overlay['teams']['blue']['name'] = trim((string) $data['blue_name']);
        $overlay['teams']['red']['avatar_url'] = $this->cleanUrl($data['red_avatar_url'] ?? null);
        $overlay['teams']['blue']['avatar_url'] = $this->cleanUrl($data['blue_avatar_url'] ?? null);

        $overlay['style'] = [
            'panel' => (bool) ($data['panel'] ?? false),
            'opacity' => (int) $data['opacity'],
            'blur' => (int) $data['blur'],
        ];

        $this->overlays->save($overlay);

        return back()->with('success', 'Overlay mis à jour.');
    }

    /**
     * POST /admin/overlay/{token}/avatar — upload de l'avatar d'une équipe.
     */
    public function avatar(Request $request, string $token): RedirectResponse
    {
        Auth::requireAdmin();

        $this->requireOverlay($token);

        $data = $request->validate([
            'team' => ['required', 'string', 'in:red,blue'],
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:'.$this->overlays->maxAvatarKb()],
        ]);

        if (! $this->overlays->saveAvatar($token, (string) $data['team'], $data['avatar'])) {
            return back()->with('error', 'Format d\'avatar non autorisé (jpeg, png, webp).');
        }

        return back()->with('success', 'Avatar enregistré.');
    }

    /**
     * POST /admin/overlay/{token}/avatar/delete — supprime l'avatar uploadé.
     */
    public function avatarDelete(Request $request, string $token): RedirectResponse
    {
        Auth::requireAdmin();

        $this->requireOverlay($token);

        $data = $request->validate([
            'team' => ['required', 'string', 'in:red,blue'],
        ]);

        $this->overlays->deleteAvatar($token, (string) $data['team']);

        return back()->with('success', 'Avatar supprimé.');
    }

    /**
     * POST /admin/overlay/{token}/refresh — relit le log logs.tf (scores et
     * stats à jour, ex. fin de map) en conservant noms, avatars et style.
     */
    public function refresh(Request $request, string $token): RedirectResponse
    {
        Auth::requireAdmin();

        $overlay = $this->requireOverlay($token);

        $payload = $this->stats->buildPayload((int) $overlay['log_id']);
        if ($payload === null) {
            return back()->with('error', 'Impossible de relire le log logs.tf, réessayez plus tard.');
        }

        // Les personnalisations (noms, avatars, style) priment sur le refresh.
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
        Auth::requireAdmin();

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
     * Ajoute les champs personnalisables (avatar) aux équipes du payload.
     *
     * @param  array<string, array<string, mixed>>  $teams
     * @return array<string, array<string, mixed>>
     */
    private function withAvatarDefaults(array $teams): array
    {
        foreach (['red', 'blue'] as $team) {
            $teams[$team]['avatar_url'] = $teams[$team]['avatar_url'] ?? null;
        }

        return $teams;
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
     * @return array<string, mixed>
     */
    private function defaultStyle(): array
    {
        return ['panel' => true, 'opacity' => 70, 'blur' => 6];
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
