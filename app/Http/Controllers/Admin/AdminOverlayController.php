<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OverlayRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\Etf2lTeamService;
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
 * Les équipes sont présentées comme A / B : les couleurs rouge/bleu du
 * log logs.tf sont arbitraires, mais le remplissage assisté ETF2L
 * retrouve le roster de chaque équipe et le service aligne
 * automatiquement les côtés du payload sur ces rosters (voir
 * OverlayStatsService::alignTeamsByRosters) — le bouton d'interversion
 * de la page d'édition reste le repli manuel et échange alors tout un
 * côté (nom, avatar, score, stats, roster).
 * Les noms d'équipes et avatars (URL externe) sont personnalisables, avec
 * le même remplissage assisté ETF2L que les autres outils overlay
 * (compétition → équipes via Etf2lTeamService) ; la vue overlay se
 * rafraîchit automatiquement dès que le payload change (polling de
 * version côté navigateur).
 */
final class AdminOverlayController extends Controller
{
    private const MAX_NAME_LEN = 64;

    private OverlayRepository $overlays;

    private OverlayStatsService $stats;

    private Etf2lTeamService $etf2lTeams;

    public function __construct()
    {
        $this->overlays = new OverlayRepository;
        $this->stats = new OverlayStatsService;
        $this->etf2lTeams = new Etf2lTeamService;
    }

    /**
     * Compétitions ETF2L pour le remplissage assisté (liste vide si l'API
     * est indisponible : la boîte est masquée, la saisie manuelle reste
     * possible).
     *
     * @return array<int, array{id: int, name: string, archived: bool}>
     */
    private function competitions(): array
    {
        try {
            return $this->etf2lTeams->competitions();
        } catch (\Throwable) {
            return [];
        }
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
            'competitions' => $this->competitions(),
        ]);
    }

    /**
     * POST /admin/overlay/generate — extrait l'ID du log depuis une URL ou
     * un identifiant saisi, récupère les stats logs.tf et crée l'overlay.
     * Les noms d'équipes et avatars par URL saisis dès la génération sont
     * appliqués au payload, pour préparer l'overlay en amont du stream.
     *
     * Si les deux équipes sont liées à ETF2L (remplissage assisté), leurs
     * rosters sont récupérés et les côtés du payload sont alignés
     * automatiquement : l'équipe A atterrit toujours du côté A, quelle
     * que soit la couleur que le jeu lui a donnée sur ce log.
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
            'red_etf2l_id' => ['nullable', 'integer', 'min:1'],
            'blue_etf2l_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $logId = $this->parseLogId((string) $data['log']);
        if ($logId === null) {
            return back()->with('error', 'Lien logs.tf invalide. Formats acceptés : https://logs.tf/12345678, https://logs.tf/#12345678 ou 12345678.');
        }

        $payload = $this->stats->buildPayload($logId);
        if ($payload === null) {
            return back()->with('error', "Log logs.tf #$logId introuvable ou sans données de joueurs.");
        }

        // Alignement des couleurs du log sur les rosters ETF2L (s'ils ont
        // pu être récupérés) : chaque clé red/blue du payload porte alors
        // les stats de l'équipe correspondante, avant application des
        // personnalisations saisies (noms, avatars) qui suivent les clés.
        $rosters = $this->fetchRosters($data);
        $alignment = OverlayStatsService::alignTeamsByRosters(
            $payload,
            $rosters['red']['players'] ?? [],
            $rosters['blue']['players'] ?? [],
        );
        $payload = $alignment['payload'];

        $token = bin2hex(random_bytes(8));
        $overlay = array_merge($payload, [
            'token' => $token,
            'created_at' => time(),
            'teams' => $this->withGenerationInputs($payload['teams'], $data),
        ]);
        $this->attachRosters($overlay['teams'], $data, $rosters);

        $this->overlays->save($overlay);
        AdminLogger::log('admin_overlay_generate', null, 'SUCCESS (overlay '.$token.' depuis logs.tf #'.$logId.')');

        $message = "Overlay créé pour le log logs.tf #$logId.";
        if ($alignment['swapped']) {
            $message .= ' Côtés alignés automatiquement sur les rosters ETF2L (couleurs du log inversées).';
        } elseif ($alignment['ambiguous'] && $this->bothEtf2lIds($data)) {
            // Deux causes distinctes d'ambiguïté : le roster n'a pas pu
            // être récupéré (API indisponible) ou il est trop peu couvert
            // par ce log (mauvaises équipes, mercs massifs).
            $unavailable = [];
            foreach (['red' => 'A', 'blue' => 'B'] as $team => $label) {
                if ($rosters[$team] === null) {
                    $unavailable[] = 'équipe '.$label;
                }
            }

            $message .= $unavailable !== []
                ? ' Roster ETF2L indisponible ('.implode(', ', $unavailable).') : alignement automatique impossible, vérifiez les côtés.'
                : ' Attention : les rosters ETF2L sont peu couverts par ce log, l\'alignement des côtés est incertain — vérifiez l\'aperçu.';
        }

        return redirect('/admin/overlay/'.$token)->with('success', $message);
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
            'competitions' => $this->competitions(),
        ]);
    }

    /**
     * POST /admin/overlay/{token}/update — noms des équipes A / B,
     * avatars par URL externe et liaison ETF2L : si une équipe est liée
     * à son équipe ETF2L (remplissage assisté), son roster est récupéré
     * et stocké dans l'overlay pour l'alignement automatique des côtés
     * aux prochains rafraîchissements. Sans liaison saisie, le roster
     * éventuellement stocké est conservé tel quel.
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
            'red_etf2l_id' => ['nullable', 'integer', 'min:1'],
            'blue_etf2l_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $overlay['teams']['red']['name'] = trim((string) $data['red_name']);
        $overlay['teams']['blue']['name'] = trim((string) $data['blue_name']);
        $overlay['teams']['red']['avatar_url'] = $this->cleanUrl($data['red_avatar_url'] ?? null);
        $overlay['teams']['blue']['avatar_url'] = $this->cleanUrl($data['blue_avatar_url'] ?? null);

        // Liaison ETF2L : le roster sert de référence d'identité, il ne
        // remplace ni le nom ni l'avatar saisis (liberté de personnalisation).
        $rosters = $this->fetchRosters($data);
        $this->attachRosters($overlay['teams'], $data, $rosters);

        $this->overlays->save($overlay);

        // Une liaison demandée mais introuvable (API indisponible) ne
        // bloque pas l'enregistrement des noms : le roster éventuellement
        // déjà stocké est conservé et l'admin est prévenu pour réessayer.
        $unavailable = [];
        foreach (['red' => 'A', 'blue' => 'B'] as $team => $label) {
            if ((int) ($data[$team.'_etf2l_id'] ?? 0) > 0 && $rosters[$team] === null) {
                $unavailable[] = 'équipe '.$label;
            }
        }

        if ($unavailable !== []) {
            return back()->with('success', 'Overlay mis à jour.')
                ->with('error', 'Roster ETF2L indisponible ('.implode(', ', $unavailable).') : la liaison n\'a pas pu être enregistrée, réessayez plus tard.');
        }

        return back()->with('success', 'Overlay mis à jour.');
    }

    /**
     * POST /admin/overlay/{token}/swap — intervertit les équipes A et B
     * côté affichage : tout un côté change de main (noms, avatars par
     * URL et historiques, score, stats des joueurs et des medics,
     * rosters ETF2L) pour que chaque équipe rejoigne l'autre côté de
     * l'écran. Les rosters voyagent avec leur équipe : le prochain
     * rafraîchissement depuis logs.tf conserve ce nouvel ordre.
     */
    public function swap(string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        $overlay = OverlayStatsService::swapTeams($overlay);

        $this->overlays->save($overlay);
        $this->overlays->swapAvatars($token);
        AdminLogger::log('admin_overlay_swap', null, 'SUCCESS (overlay '.$token.')');

        return back()->with('success', 'Équipes interverties : chaque équipe rejoint l\'autre côté de l\'overlay (nom, avatar, score, stats).');
    }

    /**
     * POST /admin/overlay/{token}/refresh — relit le log logs.tf (scores et
     * stats à jour, ex. fin de map) en conservant noms, avatars et rosters,
     * puis réaligne les couleurs du log sur les rosters stockés : l'ordre
     * d'affichage choisi (automatique ou via le bouton d'interversion)
     * traverse les rafraîchissements.
     */
    public function refresh(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        $payload = $this->stats->buildPayload((int) $overlay['log_id']);
        if ($payload === null) {
            return back()->with('error', 'Impossible de relire le log logs.tf, réessayez plus tard.');
        }

        // Les couleurs rouge/bleu d'un log sont arbitraires : les rosters
        // stockés remettent chaque équipe sur son côté avant fusion des
        // personnalisations (noms, avatars), qui suivent les clés red/blue.
        $alignment = OverlayStatsService::alignTeamsByRosters(
            $payload,
            is_array($overlay['teams']['red']['roster'] ?? null) ? $overlay['teams']['red']['roster'] : [],
            is_array($overlay['teams']['blue']['roster'] ?? null) ? $overlay['teams']['blue']['roster'] : [],
        );

        // Les personnalisations (noms, avatars, rosters) priment sur le refresh.
        $overlay = array_merge($overlay, $alignment['payload'], [
            'token' => $token,
            'teams' => $this->mergeTeams($overlay['teams'], $alignment['payload']['teams']),
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
     * avec repli sur les valeurs par défaut du payload logs.tf. Le
     * remplissage assisté des équipes vit côté éditeur (compétition →
     * équipes ETF2L), partagé avec les autres outils overlay.
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

        return $teams;
    }

    /**
     * Conserve noms, avatars et rosters personnalisés, met à jour les scores.
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
            $fresh[$team]['etf2l_id'] = $current[$team]['etf2l_id'] ?? null;
            $fresh[$team]['roster'] = is_array($current[$team]['roster'] ?? null) ? $current[$team]['roster'] : [];
        }

        return $fresh;
    }

    /**
     * Récupère les rosters ETF2L des deux équipes depuis les
     * identifiants soumis par le remplissage assisté (absents si les
     * équipes ont été saisies à la main). Null par équipe : pas de
     * liaison, ou API indisponible — l'alignement automatique des
     * côtés devient alors impossible, l'admin garde la main via le
     * bouton d'interversion.
     *
     * @param  array<string, mixed>  $data
     * @return array{red: array{id: int, name: string, players: array<int, string>}|null, blue: array{id: int, name: string, players: array<int, string>}|null}
     */
    private function fetchRosters(array $data): array
    {
        $rosters = ['red' => null, 'blue' => null];

        foreach (['red', 'blue'] as $team) {
            $id = (int) ($data[$team.'_etf2l_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            try {
                $roster = $this->etf2lTeams->roster($id);
            } catch (\Throwable) {
                $roster = null;
            }

            if ($roster !== null) {
                $rosters[$team] = $roster;
            }
        }

        return $rosters;
    }

    /**
     * Attache la liaison ETF2L aux équipes de l'overlay : identifiant
     * et roster (SteamID64 des joueurs) stockés dans le payload, pour
     * l'alignement automatique des couleurs de log aux rafraîchissements.
     *
     * @param  array<string, array<string, mixed>>  $teams
     * @param  array<string, mixed>  $data
     * @param  array{red: array{id: int, name: string, players: array<int, string>}|null, blue: array{id: int, name: string, players: array<int, string>}|null}  $rosters
     */
    private function attachRosters(array &$teams, array $data, array $rosters): void
    {
        foreach (['red', 'blue'] as $team) {
            $id = (int) ($data[$team.'_etf2l_id'] ?? 0);
            if ($id > 0 && $rosters[$team] !== null) {
                $teams[$team]['etf2l_id'] = $id;
                $teams[$team]['roster'] = $rosters[$team]['players'];
            }
        }
    }

    /**
     * Les deux équipes sont-elles liées à ETF2L dans la saisie ?
     *
     * @param  array<string, mixed>  $data
     */
    private function bothEtf2lIds(array $data): bool
    {
        return (int) ($data['red_etf2l_id'] ?? 0) > 0 && (int) ($data['blue_etf2l_id'] ?? 0) > 0;
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
