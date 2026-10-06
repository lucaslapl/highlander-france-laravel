<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OverlayRepository;
use App\Services\MatchFormat;
use App\Services\OverlayStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Vue overlay publique pour OBS Studio (source navigateur web).
 *
 * L'URL contient un token aléatoire (16 caractères) : pas d'authentification
 * par session — OBS ne peut pas s'authentifier — mais l'URL est impossible
 * à deviner et ne contient aucune donnée sensible (stats de match publiques
 * sur logs.tf). La page est entièrement transparente (1920x1080) et se
 * rafraîchit automatiquement via le polling de /overlay/{token}/version.
 */
final class OverlayController extends Controller
{
    private OverlayRepository $overlays;

    private OverlayStatsService $stats;

    public function __construct()
    {
        $this->overlays = new OverlayRepository;
        $this->stats = new OverlayStatsService;
    }

    /**
     * GET /overlay/{token} — page d'overlay (à pointer dans OBS).
     */
    public function show(string $token): Response
    {
        $overlay = $this->overlays->find($token);
        if ($overlay === null) {
            abort(404);
        }

        // Les bests sont recalculés au rendu (et non figés dans le JSON) :
        // un changement de logique s'applique aux overlays existants.
        $this->stats->applyBestStats($overlay);

        // Nom de map raccourci au rendu (« koth_product_final » → « Product »)
        // pour la même raison : les overlays existants en profitent sans
        // avoir à être régénérés depuis logs.tf.
        if (($overlay['map'] ?? '') !== '') {
            $overlay['map'] = MatchFormat::mapDisplay((string) $overlay['map']);
        }

        return response()
            ->view('overlay.match', [
                'title' => 'Overlay stats - '.($overlay['title'] !== '' ? $overlay['title'] : 'logs.tf #'.$overlay['log_id']),
                'overlay' => $overlay,
                'avatars' => $this->avatarUrls($overlay),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * URL d'avatar effective par équipe : URL externe si renseignée, sinon
     * l'avatar uploadé servi par /overlay/{token}/avatar/{team}.
     *
     * @param  array<string, mixed>  $overlay
     * @return array<string, string|null>
     */
    private function avatarUrls(array $overlay): array
    {
        $urls = [];

        foreach (['red', 'blue'] as $team) {
            $external = (string) ($overlay['teams'][$team]['avatar_url'] ?? '');
            if ($external !== '') {
                $urls[$team] = $external;
            } elseif ($this->overlays->hasAvatar((string) $overlay['token'], $team)) {
                $urls[$team] = '/overlay/'.$overlay['token'].'/avatar/'.$team;
            } else {
                $urls[$team] = null;
            }
        }

        return $urls;
    }

    /**
     * GET /overlay/{token}/version — version courante du payload, interrogée
     * par la page overlay pour se rafraîchir quand l'admin la met à jour.
     */
    public function version(string $token): JsonResponse
    {
        $overlay = $this->overlays->find($token);
        if ($overlay === null) {
            abort(404);
        }

        return response()
            ->json(['version' => (int) ($overlay['version'] ?? 0)])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * GET /overlay/{token}/avatar/{team} — sert l'avatar d'équipe uploadé
     * depuis le panel admin (stocké hors public/, servi ici uniquement).
     */
    public function avatar(string $token, string $team): BinaryFileResponse
    {
        $avatar = $this->overlays->avatar($token, $team);
        if ($avatar === null) {
            abort(404);
        }

        return response()->file($avatar['path'], [
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
