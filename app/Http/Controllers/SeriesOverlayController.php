<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SeriesRepository;
use App\Services\MatchFormat;
use App\Services\OverlayStatsService;
use App\Services\SeriesScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    private OverlayStatsService $stats;

    public function __construct()
    {
        $this->series = new SeriesRepository;
        $this->stats = new OverlayStatsService;
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
        // existantes sans regeneration. Miniature de fond par map si elle
        // existe dans le dossier public des miniatures ETF2L.
        foreach ($state['maps'] as $i => $map) {
            $state['maps'][$i]['display'] = MatchFormat::mapDisplay((string) $map['name']);
            $state['maps'][$i]['thumb'] = $this->mapThumbnailUrl((string) $map['name']);
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

    /**
     * GET /series-overlay/{token}/match — overlay de stats de la dernière map
     * terminée de la série : même rendu que l'overlay match, mais alimenté
     * automatiquement par le réconciliateur logs.tf (clé « stats » du payload
     * de série, régénérée à chaque log rattaché) et aligné sur les équipes de
     * série — aucun copier-coller d'URL logs.tf pendant le cast.
     *
     * Avant le premier log, la page affiche les deux équipes à 0-0 sans
     * stats : la source OBS peut être ajoutée en amont du stream.
     */
    public function match(string $token): Response
    {
        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $stats = is_array($series['stats'] ?? null) ? $series['stats'] : [];

        // Noms et scores d'équipes : repli sur les équipes de série à 0-0
        // tant qu'aucun log n'a été rattaché ; sinon payload du réconciliateur.
        $overlay = array_merge([
            'log_id' => 0,
            'map' => '',
            'length' => 0,
            'teams' => [
                'red' => ['name' => (string) $series['teams']['red']['name'], 'score' => 0],
                'blue' => ['name' => (string) $series['teams']['blue']['name'], 'score' => 0],
            ],
            'players' => ['red' => [], 'blue' => []],
            'medics' => [],
        ], $stats);

        // Les noms affichés sont toujours ceux de la série, jamais ceux du
        // log (RED/BLU génériques de logs.tf).
        foreach (['red', 'blue'] as $team) {
            $overlay['teams'][$team]['name'] = (string) $series['teams'][$team]['name'];
        }

        // Les bests sont recalculés au rendu (et non figés dans le JSON) :
        // un changement de logique s'applique aux séries existantes.
        $this->stats->applyBestStats($overlay);

        // Nom de map raccourci au rendu, pour la même raison.
        if (($overlay['map'] ?? '') !== '') {
            $overlay['map'] = MatchFormat::mapDisplay((string) $overlay['map']);
        }

        $overlay['token'] = $token;
        $overlay['version'] = (int) ($series['version'] ?? 0);

        return response()
            ->view('overlay.match', [
                'title' => 'Overlay match série - '.($series['title'] !== '' ? $series['title'] : $token),
                'overlay' => $overlay,
                'avatars' => $this->avatarUrls($series),
                'versionUrl' => '/series-overlay/'.$token.'/version',
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * URL d'avatar effective par équipe : URL externe si renseignée, sinon
     * l'avatar uploadé servi par /series-overlay/{token}/avatar/{team}.
     *
     * @param  array<string, mixed>  $series
     * @return array<string, string|null>
     */
    private function avatarUrls(array $series): array
    {
        $urls = [];
        $token = (string) $series['token'];

        foreach (['red', 'blue'] as $team) {
            $external = (string) ($series['teams'][$team]['avatar_url'] ?? '');
            if ($external !== '') {
                $urls[$team] = $external;
            } elseif ($this->series->hasAvatar($token, $team)) {
                $urls[$team] = '/series-overlay/'.$token.'/avatar/'.$team;
            } else {
                $urls[$team] = null;
            }
        }

        return $urls;
    }

    /**
     * GET /series-overlay/{token}/avatar/{team} — sert l'avatar d'équipe
     * uploadé depuis l'outil série (stocké hors public/, servi ici uniquement).
     */
    public function avatar(string $token, string $team): BinaryFileResponse
    {
        if ($this->series->find($token) === null) {
            abort(404);
        }

        $avatar = $this->series->avatar($token, $team);
        if ($avatar === null) {
            abort(404);
        }

        return response()->file($avatar['path'], [
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * URL de la miniature publique d'une map de la série (dossier
     * storage/etf2l-maps/thumbnails, alimenté par maps:import-thumbnails
     * et l'admin), ou null si aucune ne correspond.
     *
     * La correspondance est d'abord exacte (« koth_product_final.jpg »),
     * puis par préfixe sur le nom débarrassé de son suffixe de version
     * (« pl_upward_f10 » → « pl_upward_f12.jpg »), la plus longue gagne —
     * même convention de rapprochement que SeriesScoreService::matchSeriesMap.
     */
    private function mapThumbnailUrl(string $mapName): ?string
    {
        $name = strtolower(trim($mapName));
        if ($name === '') {
            return null;
        }

        $best = null;
        $bestLen = -1;
        foreach (glob(public_path('storage/etf2l-maps/thumbnails').'/*') ?: [] as $file) {
            $base = basename($file);
            if (preg_match('/^([A-Za-z0-9._-]+)\.(jpe?g|png|webp)$/i', $base, $m) !== 1) {
                continue;
            }

            $thumbName = strtolower($m[1]);
            if ($thumbName !== $name
                && ! str_starts_with($thumbName, strtolower((string) preg_replace('/_(final|rc|v|b|f)\d*$/i', '', $name)).'_')
                && ! str_starts_with($thumbName, $name.'_')) {
                continue;
            }

            if (strlen($thumbName) > $bestLen) {
                $best = $base;
                $bestLen = strlen($thumbName);
            }
        }

        return $best !== null ? asset('storage/etf2l-maps/thumbnails/'.$best) : null;
    }
}
