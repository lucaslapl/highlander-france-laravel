<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PickBanRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Vue overlay publique du pick / ban de maps (OBS Studio, source
 * navigateur web), sur le même modèle que les overlays de stats, de
 * série et de bracket : l'URL contient un token aléatoire (16 caractères)
 * impossible à deviner, sans authentification par session (OBS ne peut
 * pas s'authentifier) et sans donnée sensible — noms d'équipes et maps
 * de matchs publics.
 *
 * La page est entièrement transparente (1920x1080) et se rafraîchit
 * automatiquement via le polling de /pickban-overlay/{token}/version :
 * chaque enregistrement côté admin bump la version. Rendu 100 % côté
 * serveur, aucune donnée n'est chargée en JavaScript.
 */
final class PickBanOverlayController extends Controller
{
    private PickBanRepository $pickbans;

    public function __construct()
    {
        $this->pickbans = new PickBanRepository;
    }

    /**
     * GET /pickban-overlay/{token} — page d'overlay (à pointer dans OBS).
     */
    public function show(string $token): Response
    {
        $overlay = $this->pickbans->find($token);
        if ($overlay === null) {
            abort(404);
        }

        $name = trim(((string) ($overlay['title'] ?? '')) !== '' ? (string) $overlay['title'] : $token);

        return response()
            ->view('overlay.pickban', [
                'title' => 'Overlay pick/ban - '.$name,
                'token' => $token,
                'version' => (int) ($overlay['version'] ?? 0),
                'data' => $this->viewData($overlay),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * GET /pickban-overlay/{token}/version — version courante du payload,
     * interrogée par la page overlay pour se rafraîchir.
     */
    public function version(string $token): JsonResponse
    {
        $overlay = $this->pickbans->find($token);
        if ($overlay === null) {
            abort(404);
        }

        return response()
            ->json(['version' => (int) ($overlay['version'] ?? 0)])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Prépare les données de rendu : titres, équipes A / B et les six
     * cartes résolues (map, capture, action, équipe affichée).
     *
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function viewData(array $overlay): array
    {
        $teams = [
            'a' => [
                'name' => (string) ($overlay['teams']['a']['name'] ?? ''),
                'avatar' => (string) ($overlay['teams']['a']['avatar'] ?? ''),
            ],
            'b' => [
                'name' => (string) ($overlay['teams']['b']['name'] ?? ''),
                'avatar' => (string) ($overlay['teams']['b']['avatar'] ?? ''),
            ],
        ];

        $cards = [];
        foreach ($overlay['cards'] ?? [] as $card) {
            if (! is_array($card)) {
                continue;
            }

            $action = ($card['action'] ?? '') === 'pick' ? 'pick' : 'ban';
            $teamSide = in_array($card['team'] ?? '', ['a', 'b'], true) ? (string) $card['team'] : '';

            $cards[] = [
                'map' => (string) ($card['map'] ?? ''),
                'image' => (string) ($card['image'] ?? ''),
                'action' => $action,
                'team' => $teamSide !== '' ? $teams[$teamSide] : ['name' => '', 'avatar' => ''],
            ];
        }

        return [
            'eyebrow' => (string) ($overlay['eyebrow'] ?? ''),
            'title' => (string) ($overlay['title'] ?? ''),
            'teams' => $teams,
            'cards' => $cards,
        ];
    }
}
