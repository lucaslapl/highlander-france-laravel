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
 * Deux types d'URL, toutes deux sans authentification par session — OBS ne
 * peut pas s'authentifier — et sans donnée sensible (stats de match
 * publiques sur logs.tf) :
 *
 *  - /overlay/match-logs : URL UNIQUE de l'outil « Overlay Logs ». Tous les
 *    logs y sont chargés (lien saisi ou « Charger ce log » depuis l'outil
 *    admin) et seul le visuel change : à pointer une seule fois dans OBS,
 *    jamais à retoucher.
 *  - /overlay/{token} : overlays individuels historiques (token aléatoire
 *    de 16 caractères, impossible à deviner), conservés pour les sources
 *    OBS déjà configurées.
 *
 * Les pages sont entièrement transparentes (1920x1080) et se rafraîchissent
 * automatiquement via le polling de leur endpoint /version.
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
     * GET /overlay/{token} — page d'overlay historique (à pointer dans OBS).
     */
    public function show(string $token): Response
    {
        $overlay = $this->overlays->find($token);
        if ($overlay === null) {
            abort(404);
        }

        return $this->render($overlay, '/overlay/'.$token);
    }

    /**
     * GET /overlay/match-logs — overlay unique de l'outil « Overlay Logs ».
     * Tant qu'aucun log n'a été chargé, la page est entièrement transparente
     * et poll sa version : dès que le premier log arrive, elle se recharge
     * toute seule et affiche les stats.
     */
    public function showFixed(): Response
    {
        $overlay = $this->overlays->find(OverlayRepository::MATCH_LOGS_TOKEN);
        if ($overlay === null) {
            return response()
                ->view('overlay.match_empty')
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        return $this->render($overlay, '/overlay/match-logs');
    }

    /**
     * Rendu commun : bests recalculés au rendu (et non figés dans le JSON) —
     * un changement de logique s'applique aux overlays existants — et nom de
     * map raccourci (« koth_product_final » → « Product ») pour la même
     * raison. $baseUrl porte l'URL publique (fixe ou à token) : version et
     * avatars sont servis sous la même forme que la page.
     *
     * @param  array<string, mixed>  $overlay
     */
    private function render(array $overlay, string $baseUrl): Response
    {
        $this->stats->applyBestStats($overlay);

        if (($overlay['map'] ?? '') !== '') {
            $overlay['map'] = MatchFormat::mapDisplay((string) $overlay['map']);
        }

        return response()
            ->view('overlay.match', [
                'title' => 'Overlay stats - '.($overlay['title'] !== '' ? $overlay['title'] : 'logs.tf #'.$overlay['log_id']),
                'overlay' => $overlay,
                'avatars' => $this->avatarUrls($overlay, $baseUrl),
                'versionUrl' => $baseUrl.'/version',
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * URL d'avatar effective par équipe : URL externe si renseignée, sinon
     * l'avatar uploadé servi par {baseUrl}/avatar/{team}.
     *
     * @param  array<string, mixed>  $overlay
     * @return array<string, string|null>
     */
    private function avatarUrls(array $overlay, string $baseUrl): array
    {
        $urls = [];

        foreach (['red', 'blue'] as $team) {
            $external = (string) ($overlay['teams'][$team]['avatar_url'] ?? '');
            if ($external !== '') {
                $urls[$team] = $external;
            } elseif ($this->overlays->hasAvatar((string) $overlay['token'], $team)) {
                $urls[$team] = $baseUrl.'/avatar/'.$team;
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

        return $this->versionResponse((int) ($overlay['version'] ?? 0));
    }

    /**
     * GET /overlay/match-logs/version — version de l'overlay unique, 0 tant
     * qu'aucun log n'a été chargé (la page vide poll et se recharge au
     * premier chargement).
     */
    public function versionFixed(): JsonResponse
    {
        $overlay = $this->overlays->find(OverlayRepository::MATCH_LOGS_TOKEN);

        return $this->versionResponse((int) ($overlay['version'] ?? 0));
    }

    private function versionResponse(int $version): JsonResponse
    {
        return response()
            ->json(['version' => $version])
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

        return $this->avatarResponse($avatar);
    }

    /**
     * GET /overlay/match-logs/avatar/{team} — avatar d'équipe de l'overlay
     * unique, sous la même URL fixe que la page.
     */
    public function avatarFixed(string $team): BinaryFileResponse
    {
        return $this->avatar(OverlayRepository::MATCH_LOGS_TOKEN, $team);
    }

    /**
     * @param  array{path: string, ext: string}  $avatar
     */
    private function avatarResponse(array $avatar): BinaryFileResponse
    {
        return response()->file($avatar['path'], [
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
