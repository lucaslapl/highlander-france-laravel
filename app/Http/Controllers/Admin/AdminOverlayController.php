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

/**
 * Outil « Overlay Logs » : overlay de stats pour OBS Studio alimenté
 * par des logs logs.tf. Accessible aux admins et aux rôles caster / prod
 * (hub des overlays /admin/overlays-stream).
 *
 * Une URL publique UNIQUE sert tous les chargements de logs :
 * /overlay/match-logs. Elle se pointe une seule fois dans OBS (source
 * navigateur web) et ne change jamais ; chaque log chargé (lien saisi
 * ou bouton « Charger ce log » sur un log déjà généré) remplace le
 * contenu — mêmes équipes couleur pour couleur, le visuel suit le log
 * — et la vue OBS se rafraîchit toute seule (polling de version côté
 * navigateur). Les overlays à token individuel créés avant cette URL
 * unique restent listés comme historique de logs rechargeables, et
 * leurs pages publiques /overlay/{token} continuent de fonctionner
 * pour les sources OBS déjà configurées.
 *
 * Les noms et avatars d'équipes sont résolus automatiquement à chaque
 * chargement de log : les joueurs votent pour leurs équipes ETF2L
 * (rosters synchronisés + payloads API en cache, voir
 * OverlayStatsService) et le nom le plus voté de chaque côté s'affiche
 * avec l'avatar de son club (payload joueur, sinon endpoint équipe mis
 * en cache) — sans appel HTTP supplémentaire par rapport à la
 * résolution des pseudos, hormis l'avatar au premier chargement du
 * jour. Sans nom identifiable, le repli est RED / BLU : plus besoin de
 * remplissage assisté ni de bouton d'interversion, les couleurs du log
 * sont suivies telles quelles. La seule saisie de l'outil est le lien du
 * log à charger.
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
     * GET /admin/overlay — l'outil : URL OBS unique à pointer une seule
     * fois dans OBS, chargement d'un log par lien, et historique des logs
     * déjà générés avec un bouton « Charger ce log » par entrée.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.overlay', [
            'title' => 'Admin - Overlay Logs (OBS)',
            'description' => 'Overlay de stats logs.tf pour les broadcasts OBS de Highlander France.',
            'overlay_url' => url('/overlay/match-logs'),
            'current' => $this->overlays->find(OverlayRepository::MATCH_LOGS_TOKEN),
            'logs' => $this->historyLogs(),
        ]);
    }

    /**
     * POST /admin/overlay/load — extrait l'ID du log depuis une URL ou un
     * identifiant saisi, récupère les stats logs.tf et les charge dans
     * l'overlay unique (/overlay/match-logs) : l'URL OBS ne change pas,
     * seul le visuel est remplacé. Les noms d'équipes ETF2L sont résolus
     * automatiquement.
     */
    public function loadLog(Request $request): RedirectResponse
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

        $overlay = $this->saveToUniqueOverlay($payload);
        AdminLogger::log('admin_overlay_load', null, 'SUCCESS (overlay unique ← logs.tf #'.$logId.')');

        return back()->with('success', "Log logs.tf #$logId chargé dans l'overlay unique : l'URL OBS ne change pas, l'overlay s'est mis à jour tout seul.".$this->teamNamesMessage($overlay));
    }

    /**
     * POST /admin/overlay/{token}/load — « Charger ce log » : recharge le
     * log d'un overlay de l'historique dans l'overlay unique, sans appel
     * logs.tf (payload déjà résolu et persisté) et sans toucher à l'URL
     * OBS. Les avatars uploadés historiques suivent le log.
     */
    public function loadFromHistory(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->overlays->find($token);
        if ($overlay === null) {
            abort(404);
        }

        // Le payload historique est repris tel quel : seules les clés de
        // persistance (token, dates, version) n'appartiennent pas au log.
        $payload = array_diff_key($overlay, array_flip(['token', 'created_at', 'updated_at', 'version']));

        $unique = $this->saveToUniqueOverlay($payload);
        $this->overlays->copyAvatars($token, OverlayRepository::MATCH_LOGS_TOKEN);
        AdminLogger::log('admin_overlay_load', null, 'SUCCESS (overlay unique ← logs.tf #'.$overlay['log_id'].' via historique)');

        return back()->with('success', 'Log logs.tf #'.$overlay['log_id']." chargé dans l'overlay unique : l'URL OBS ne change pas, l'overlay s'est mis à jour tout seul.".$this->teamNamesMessage($unique));
    }

    /**
     * POST /admin/overlay/refresh — relit depuis logs.tf le log courant de
     * l'overlay unique (scores et stats à jour, ex. fin de map) : une
     * résolution ETF2L (noms, avatars) indisponible au chargement du log
     * est rattrapée ici dès que l'API répond à nouveau.
     */
    public function refresh(Request $request): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->overlays->find(OverlayRepository::MATCH_LOGS_TOKEN);
        if ($overlay === null) {
            return back()->with('error', "Aucun log chargé pour l'instant : chargez d'abord un log.");
        }

        $payload = $this->stats->buildPayload((int) $overlay['log_id']);
        if ($payload === null) {
            return back()->with('error', 'Impossible de relire le log logs.tf, réessayez plus tard.');
        }

        $overlay = $this->saveToUniqueOverlay($payload);

        return back()->with('success', 'Stats rafraîchies depuis logs.tf.'.$this->teamNamesMessage($overlay));
    }

    /**
     * POST /admin/overlay/{token}/delete — supprime un log de l'historique
     * (et sa page /overlay/{token}, plus pointée par personne).
     */
    public function delete(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $this->overlays->delete($token);

        return redirect('/admin/overlay')->with('success', "Log supprimé de l'historique.");
    }

    /**
     * Persiste un payload dans l'overlay unique (créé au premier
     * chargement) en conservant sa date de création, et bumper sa version
     * : la vue OBS se rafraîchit toute seule.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function saveToUniqueOverlay(array $payload): array
    {
        $existing = $this->overlays->find(OverlayRepository::MATCH_LOGS_TOKEN);
        $base = $existing ?? [
            'token' => OverlayRepository::MATCH_LOGS_TOKEN,
            'created_at' => time(),
        ];

        $overlay = OverlayStatsService::mergePayload($base, $payload);
        $this->overlays->save($overlay);

        return $overlay;
    }

    /**
     * Historique des logs déjà générés : tous les overlays sauf l'overlay
     * unique (qui est le log courant, affiché à part), du plus récent au
     * plus ancien.
     *
     * @return array<int, array<string, mixed>>
     */
    private function historyLogs(): array
    {
        $fixed = OverlayRepository::MATCH_LOGS_TOKEN;

        return array_values(array_filter(
            $this->overlays->all(),
            static fn (array $entry): bool => ($entry['token'] ?? '') !== $fixed
        ));
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
}
