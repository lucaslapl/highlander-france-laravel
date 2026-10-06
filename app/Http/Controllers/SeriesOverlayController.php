<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SeriesRepository;
use App\Services\MatchFormat;
use App\Services\SeriesScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Vue overlay publique du scoreboard de série (OBS Studio, source navigateur
 * web), sur le même modèle que l'overlay de stats match : l'URL contient un
 * token aléatoire (16 caractères) impossible à deviner, sans authentification
 * par session (OBS ne peut pas s'authentifier) et sans donnée sensible — les
 * scores de matchs publics et les noms d'équipes.
 *
 * La page est entièrement transparente (1920x1080) et se rafraîchit
 * automatiquement via le polling de /series-overlay/{token}/version : chaque
 * événement du journal (log logs.tf rattaché, point manuel, annulation) bump
 * la version et l'overlay se recharge — plus aucun alt-tab pendant le cast.
 */
final class SeriesOverlayController extends Controller
{
    private SeriesRepository $series;

    public function __construct()
    {
        $this->series = new SeriesRepository;
    }

    /**
     * GET /series-overlay/{token} — page d'overlay (à pointer dans OBS).
     */
    public function show(string $token): Response
    {
        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $state = (new SeriesScoreService)->compute($series);

        // Noms de map raccourcis au rendu (« koth_product_final » →
        // « Product ») : un changement de logique s'applique aux séries
        // existantes sans regeneration.
        foreach ($state['maps'] as $i => $map) {
            $state['maps'][$i]['display'] = MatchFormat::mapDisplay((string) $map['name']);
        }

        return response()
            ->view('overlay.series', [
                'title' => 'Overlay série - '.($series['title'] !== '' ? $series['title'] : $token),
                'series' => $series,
                'state' => $state,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * GET /series-overlay/{token}/version — version courante du payload,
     * interrogée par la page overlay pour se rafraîchir quand le journal
     * de la série évolue (cron logs.tf, action manuelle dans l'admin).
     */
    public function version(string $token): JsonResponse
    {
        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        return response()
            ->json(['version' => (int) ($series['version'] ?? 0)])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
