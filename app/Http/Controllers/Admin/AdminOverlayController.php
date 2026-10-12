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
 * Outil « Overlay Logs » : overlays de stats pour OBS Studio alimentés
 * par des logs logs.tf. Accessible aux admins et aux rôles caster / prod
 * (hub des overlays /admin/overlays-stream).
 *
 * Chaque overlay possède son propre token et une URL publique stable
 * (/overlay/{token}) : l'URL se pointe une seule fois dans OBS (source
 * navigateur web) et ne change jamais. Le chargement d'un nouveau log
 * (action « load ») remplace le contenu de l'overlay — mêmes équipes
 * couleur pour couleur, le visuel suit le log — et la vue OBS se
 * rafraîchit toute seule (polling de version côté navigateur).
 *
 * Les noms d'équipes sont résolus automatiquement à chaque chargement de
 * log : les joueurs votent pour leurs équipes ETF2L (rosters synchronisés
 * + payloads API en cache, voir OverlayStatsService) et le nom le plus
 * voté de chaque côté s'affiche — sans aucun appel HTTP supplémentaire
 * par rapport à la résolution des pseudos. Sans nom identifiable, le
 * repli est RED / BLU : plus besoin de remplissage assisté ni de bouton
 * d'interversion, les couleurs du log sont suivies telles quelles.
 * Seuls les avatars par URL externe restent personnalisables (ils
 * traversent les chargements de logs).
 */
final class AdminOverlayController extends Controller
{
    private OverlayRepository $overlays;

    private OverlayStatsService $stats;

    public function __construct()
    {
        $this->overlays = new OverlayRepository;
        $this->stats = new OverlayStatsService;
    }

    /**
     * GET /admin/overlay — liste des overlays + création d'un nouvel
     * overlay (le premier log sert d'amorce, les suivants se chargent
     * depuis la page de l'overlay, sans jamais changer l'URL OBS).
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.overlay', [
            'title' => 'Admin - Overlay Logs (OBS)',
            'description' => 'Overlays de stats logs.tf pour les broadcasts OBS de Highlander France.',
            'overlays' => $this->overlays->all(),
        ]);
    }

    /**
     * POST /admin/overlay/generate — extrait l'ID du log depuis une URL ou
     * un identifiant saisi, récupère les stats logs.tf et crée un overlay
     * (nouveau token, nouvelle URL OBS à pointer dans OBS une seule fois).
     * Les noms d'équipes ETF2L sont résolus automatiquement.
     */
    public function generate(Request $request): RedirectResponse
    {
        Auth::requireOverlayTools();

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
        ]);
        $this->overlays->save($overlay);
        AdminLogger::log('admin_overlay_generate', null, 'SUCCESS (overlay '.$token.' depuis logs.tf #'.$logId.')');

        return redirect('/admin/overlay/'.$token)
            ->with('success', "Overlay créé pour le log logs.tf #$logId.".$this->teamNamesMessage($overlay));
    }

    /**
     * GET /admin/overlay/{token} — page de l'overlay : chargement de logs,
     * avatars et URL OBS (stable, à ne pointer qu'une seule fois).
     */
    public function edit(string $token): View
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        return view('admin.overlay_edit', [
            'title' => 'Admin - Overlay Logs (OBS)',
            'description' => 'Chargement de logs logs.tf et personnalisation de l\'overlay pour les broadcasts OBS.',
            'overlay' => $overlay,
            'overlay_url' => url('/overlay/'.$overlay['token']),
        ]);
    }

    /**
     * POST /admin/overlay/{token}/load — charge un nouveau log logs.tf
     * dans l'overlay : le token (donc l'URL OBS) ne change pas, seuls les
     * contenus (équipes, scores, joueurs, medics, titre, map) sont
     * remplacés. Les noms d'équipes ETF2L sont résolus à nouveau et les
     * avatars personnalisés sont conservés. La version est bumpée par le
     * repository : la vue OBS se rafraîchit toute seule.
     */
    public function load(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

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

        $overlay = OverlayStatsService::mergePayload($overlay, $payload);
        $this->overlays->save($overlay);
        AdminLogger::log('admin_overlay_load', null, 'SUCCESS (overlay '.$token.' ← logs.tf #'.$logId.')');

        return back()->with('success', "Log logs.tf #$logId chargé : l'URL OBS ne change pas, l'overlay s'est mis à jour tout seul.".$this->teamNamesMessage($overlay));
    }

    /**
     * POST /admin/overlay/{token}/update — avatars par URL externe des
     * deux équipes : les seules personnalisations persistantes de
     * l'overlay, elles traversent les chargements de logs. Les noms
     * d'équipes ne sont pas saisissables : ils sont résolus
     * automatiquement depuis ETF2L (ou RED / BLU par repli).
     */
    public function update(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        $data = $request->validate([
            'red_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'blue_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
        ]);

        $overlay['teams']['red']['avatar_url'] = $this->cleanUrl($data['red_avatar_url'] ?? null);
        $overlay['teams']['blue']['avatar_url'] = $this->cleanUrl($data['blue_avatar_url'] ?? null);

        $this->overlays->save($overlay);

        return back()->with('success', 'Overlay mis à jour.');
    }

    /**
     * POST /admin/overlay/{token}/refresh — relit le log logs.tf courant
     * (scores et stats à jour, ex. fin de map) en conservant le token,
     * les avatars personnalisés… et les noms d'équipes résolus
     * automatiquement : une résolution ETF2L indisponible au chargement
     * du log est rattrapée ici dès que l'API répond à nouveau.
     */
    public function refresh(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        $payload = $this->stats->buildPayload((int) $overlay['log_id']);
        if ($payload === null) {
            return back()->with('error', 'Impossible de relire le log logs.tf, réessayez plus tard.');
        }

        $overlay = OverlayStatsService::mergePayload($overlay, $payload);
        $this->overlays->save($overlay);

        return back()->with('success', 'Stats rafraîchies depuis logs.tf.'.$this->teamNamesMessage($overlay));
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
     * Message de retour des chargements de logs : état de la résolution
     * automatique des noms d'équipes ETF2L (RED / BLU par repli).
     *
     * @param  array<string, mixed>  $overlay
     */
    private function teamNamesMessage(array $overlay): string
    {
        $red = (string) ($overlay['teams']['red']['name'] ?? '');
        $blue = (string) ($overlay['teams']['blue']['name'] ?? '');

        if ($red !== 'RED' && $blue !== 'BLU') {
            return ' Équipes identifiées sur ETF2L : '.$red.' vs '.$blue.'.';
        }

        if ($red !== 'RED') {
            return ' Équipe rouge identifiée sur ETF2L : '.$red.' (bleue : BLU).';
        }

        if ($blue !== 'BLU') {
            return ' Équipe bleue identifiée sur ETF2L : '.$blue.' (rouge : RED).';
        }

        return ' Aucune équipe identifiée sur ETF2L : RED / BLU affichés.';
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
