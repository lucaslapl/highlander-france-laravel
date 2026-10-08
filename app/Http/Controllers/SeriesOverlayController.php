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
 *
 * Cast sur SourceTV retardée : les URLs acceptent ?delay=N (secondes), le
 * retard du flux STV par rapport au serveur (90 s le plus souvent). Les
 * événements « log » plus récents que ce délai sont masqués du calcul — un
 * log remonte à la fin réelle de la map, alors que le flux STV la montre N
 * secondes plus tard : sans délai, l'overlay spoilerait le résultat. Les
 * points manuels et annulations passent sans délai : ils sont saisis par un
 * humain synchronisé sur le flux diffusé. Le paramètre se propage de la page
 * au polling de version, et le serveur ne propage les mises à jour qu'au
 * moment où elles deviennent visibles au regard du délai.
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

        $delay = $this->requestDelay();
        $view = $this->delayedSeries($series, $delay);
        $state = (new SeriesScoreService)->compute($view);

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
                'version' => $this->visibleVersion($series, $state, $view['stats']),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * GET /series-overlay/{token}/version — version courante du payload,
     * interrogée par la page overlay pour se rafraîchir quand le journal
     * de la série évolue (cron logs.tf, action manuelle dans l'admin).
     *
     * Avec ?delay=N, la version est une empreinte de l'état *visible* au
     * regard du délai, pas le compteur d'écritures : l'overlay retardé se
     * recharge exactement quand un événement masqué devient visible (le
     * temps avance sans nouvelle écriture), et pas avant.
     */
    public function version(string $token): JsonResponse
    {
        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $view = $this->delayedSeries($series, $this->requestDelay());
        $state = (new SeriesScoreService)->compute($view);

        return response()
            ->json(['version' => $this->visibleVersion($series, $state, $view['stats'])])
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

        $delay = $this->requestDelay();
        $view = $this->delayedSeries($series, $delay);
        $stats = is_array($view['stats']) ? $view['stats'] : [];

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
        $overlay['version'] = $this->visibleVersion(
            $series,
            (new SeriesScoreService)->compute($view),
            $view['stats']
        );

        // Le polling de version porte le même délai STV que la page : le
        // paramètre de la requête est propagé tel quel à l'endpoint.
        $versionUrl = '/series-overlay/'.$token.'/version';
        $query = request()->getQueryString();
        if ($query !== null && $query !== '') {
            $versionUrl .= '?'.$query;
        }

        return response()
            ->view('overlay.match', [
                'title' => 'Overlay match série - '.($series['title'] !== '' ? $series['title'] : $token),
                'overlay' => $overlay,
                'avatars' => $this->avatarUrls($series),
                'versionUrl' => $versionUrl,
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
     * Délai SourceTV demandé par la requête (?delay=N secondes, 0 par
     * défaut), borné : le caster adapte l'overlay au retard réel de son
     * flux STV (90 s le plus souvent, mais variable d'un broadcast à
     * l'autre — d'où un paramètre d'URL et non un réglage de série).
     */
    private function requestDelay(): int
    {
        return max(0, min(600, (int) request()->query('delay', 0)));
    }

    /**
     * Vue « retardée » d'une série pour un cast sur SourceTV différée :
     * les événements « log » plus récents que le délai sont retirés du
     * journal avant calcul — un log remonte à la fin réelle de la map,
     * alors que le flux STV la montre N secondes plus tard. Les points
     * manuels et annulations passent sans délai : ils sont saisis par un
     * humain synchronisé sur le flux diffusé.
     *
     * La clé « stats » est réalignée sur le dernier log *visible* : les
     * stats de la map terminée ne remplacent celles de la précédente qu'au
     * moment où leur log devient visible. Chaque événement « log » porte
     * son payload de stats (embarqué par le réconciliateur) ; repli sur la
     * clé « stats » de la série pour les journaux antérieurs à ce
     * rattachement, uniquement si le dernier log visible est aussi le
     * dernier log du journal.
     *
     * @param  array<string, mixed>  $series
     * @return array<string, mixed> Copie de la série : journal filtré + stats visibles.
     */
    private function delayedSeries(array $series, int $delay): array
    {
        $cutoff = time() - $delay;
        $journal = is_array($series['journal'] ?? null) ? $series['journal'] : [];

        $visible = [];
        $lastLog = null;
        $lastVisibleLog = null;

        foreach ($journal as $event) {
            $isLog = ($event['type'] ?? '') === 'log';
            if ($isLog && (int) ($event['at'] ?? 0) > $cutoff) {
                continue;
            }

            $visible[] = $event;

            if ($isLog) {
                $lastLog = $event;
                $lastVisibleLog = $event;
            }
        }

        $series['journal'] = $visible;

        $stats = null;
        if ($lastVisibleLog !== null) {
            if (is_array($lastVisibleLog['stats'] ?? null)) {
                $stats = $lastVisibleLog['stats'];
            } elseif ($lastLog !== null
                && (string) ($lastVisibleLog['log_id'] ?? '') === (string) ($lastLog['log_id'] ?? '')
                && is_array($series['stats'] ?? null)) {
                $stats = $series['stats'];
            }
        }

        $series['stats'] = $stats;

        return $series;
    }

    /**
     * Version « visible » d'une série : empreinte de l'état calculé sur le
     * journal filtré et des stats visibles, pas le compteur d'écritures —
     * l'overlay retardé se recharge exactement quand un événement masqué
     * par le délai devient visible (le temps avance sans nouvelle écriture
     * sur la série). Le compteur d'écritures participe à l'empreinte pour
     * propager aussi les changements cosmétiques (titre, équipes, avatars).
     *
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>|null  $stats
     */
    private function visibleVersion(array $series, array $state, ?array $stats): int
    {
        return (int) sprintf('%u', crc32(serialize([
            (int) ($series['version'] ?? 0),
            $state,
            $stats,
        ])));
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
