<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RostersRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Vue overlay publique des rosters d'équipes (OBS Studio, source navigateur
 * web), sur le même modèle que les overlays de stats, de série, de bracket
 * et de pick/ban : l'URL contient un token aléatoire (16 caractères)
 * impossible à deviner, sans authentification par session (OBS ne peut pas
 * s'authentifier) et sans donnée sensible — noms d'équipes et pseudos
 * publics des joueurs inscrits.
 *
 * La page est entièrement transparente (1920x1080) et se rafraîchit
 * automatiquement via le polling de /roster-overlay/{token}/version :
 * chaque enregistrement côté admin bump la version. Rendu 100 % côté
 * serveur, aucune donnée n'est chargée en JavaScript. La disposition
 * dépend du format : Highlander = une seule équipe à la fois, celle
 * pilotée côté admin (bouton « Switcher le roster affiché ») en grille
 * 3x3 des neuf classes ; 6v6 = les deux équipes empilées l'une sous
 * l'autre sur la même page, entêtes à gauche et classes en vis-à-vis
 * (colonnes alignées pour comparer qui joue quoi). Le switch bump la
 * version avec le drapeau replay_enter pour que l'animation
 * d'apparition se rejoue (Highlander uniquement, le 6v6 affiche déjà
 * les deux équipes), les autres enregistrements restent des
 * rafraîchissements statiques.
 */
final class RostersOverlayController extends Controller
{
    private RostersRepository $rosters;

    public function __construct()
    {
        $this->rosters = new RostersRepository;
    }

    /**
     * GET /roster-overlay/{token} — page d'overlay (à pointer dans OBS).
     */
    public function show(string $token): Response
    {
        $overlay = $this->rosters->find($token);
        if ($overlay === null) {
            abort(404);
        }

        $displayed = (string) ($overlay['displayed'] ?? 'a') === 'b' ? 'b' : 'a';
        $name = trim(((string) ($overlay['title'] ?? '')) !== '' ? (string) $overlay['title'] : $token);

        return response()
            ->view('overlay.rosters', [
                'title' => 'Overlay rosters - '.$name,
                'token' => $token,
                'version' => (int) ($overlay['version'] ?? 0),
                'data' => $this->viewData($overlay, $displayed),
                // Rejouer l'animation d'apparition : uniquement si le
                // dernier enregistrement est un switch (sinon le
                // rafraîchissement reste statique, voir la vue).
                'replay_enter' => (bool) ($overlay['replay_enter'] ?? false),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * GET /roster-overlay/{token}/version — version courante du payload,
     * interrogée par la page overlay pour se rafraîchir.
     */
    public function version(string $token): JsonResponse
    {
        $overlay = $this->rosters->find($token);
        if ($overlay === null) {
            abort(404);
        }

        return response()
            ->json(['version' => (int) ($overlay['version'] ?? 0)])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Prépare les données de rendu : titres et les équipes à afficher
     * (nom, avatar, joueurs par classe avec pseudo, drapeau merc et
     * portrait). En Highlander, seule l'équipe pilotée côté admin est
     * rendue ; en 6v6, les deux équipes sont rendues empilées
     * l'une sous l'autre, dans le même ordre de classes pour que les
     * colonnes s'alignent et que chaque classe soit en vis-à-vis entre
     * les deux équipes. Le portrait vient de la copie locale des bustes
     * du wiki TF2 (public/_img/classes_portraits), avec repli sur les
     * icônes de classes du site si un portrait manque.
     *
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function viewData(array $overlay, string $displayed): array
    {
        $format = (string) ($overlay['format'] ?? 'hl');

        $teams = [];
        if ($format === '6v6') {
            foreach (['a', 'b'] as $side) {
                $teams[] = $this->teamData($overlay, $side);
            }
        } else {
            $teams[] = $this->teamData($overlay, $displayed);
        }

        return [
            'format' => $format,
            'eyebrow' => (string) ($overlay['eyebrow'] ?? ''),
            'title' => (string) ($overlay['title'] ?? ''),
            'displayed' => $displayed,
            'teams' => $teams,
        ];
    }

    /**
     * Données de rendu d'une équipe : nom, avatar et joueurs par classe
     * (pseudo, drapeau merc, portrait).
     *
     * @param  array<string, mixed>  $overlay
     * @return array{side: string, name: string, avatar: string, players: array<int, array{class: string, name: string, merc: bool, portrait: string}>}
     */
    private function teamData(array $overlay, string $side): array
    {
        $team = is_array($overlay['teams'][$side] ?? null) ? $overlay['teams'][$side] : [];

        $players = [];
        foreach (is_array($team['players'] ?? null) ? $team['players'] : [] as $player) {
            if (! is_array($player)) {
                continue;
            }

            $players[] = [
                'class' => (string) ($player['class'] ?? ''),
                'name' => (string) ($player['name'] ?? ''),
                'merc' => (bool) ($player['merc'] ?? false),
                'portrait' => $this->portrait((string) ($player['class'] ?? '')),
            ];
        }

        return [
            'side' => $side,
            'name' => (string) ($team['name'] ?? ''),
            'avatar' => (string) ($team['avatar'] ?? ''),
            'players' => $players,
        ];
    }

    /**
     * Portrait local d'une classe (copie du wiki TF2), repli sur l'icône
     * de classe du site si le portrait manque.
     */
    private function portrait(string $class): string
    {
        if ($class !== '' && is_file(public_path('/_img/classes_portraits/'.$class.'.png'))) {
            return '/_img/classes_portraits/'.$class.'.png';
        }

        if ($class !== '' && is_file(public_path('/_img/classes/'.$class.'.png'))) {
            return '/_img/classes/'.$class.'.png';
        }

        return '';
    }
}
