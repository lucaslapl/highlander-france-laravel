<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BracketRepository;
use App\Models\SeriesRepository;
use App\Services\SeriesScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Vue overlay publique des brackets de playoffs et des tables de
 * classement (OBS Studio, source navigateur web), sur le même modèle que
 * les overlays de stats et de série : l'URL contient un token aléatoire
 * (16 caractères) impossible à deviner, sans authentification par session
 * (OBS ne peut pas s'authentifier) et sans donnée sensible — noms
 * d'équipes et scores de matchs publics.
 *
 * La page est entièrement transparente (1920x1080) et se rafraîchit
 * automatiquement via le polling de /bracket-overlay/{token}/version :
 * chaque enregistrement côté admin bump la version, et un match « EN
 * DIRECT » attaché à une série en propage aussi les mises à jour (points
 * manuels, logs logs.tf réconciliés, webhook match-ended).
 */
final class BracketOverlayController extends Controller
{
    private BracketRepository $brackets;

    private SeriesRepository $series;

    public function __construct()
    {
        $this->brackets = new BracketRepository;
        $this->series = new SeriesRepository;
    }

    /**
     * GET /bracket-overlay/{token} — page d'overlay (à pointer dans OBS).
     */
    public function show(string $token): Response
    {
        $bracket = $this->brackets->find($token);
        if ($bracket === null) {
            abort(404);
        }

        $name = trim(((string) ($bracket['title'] ?? '')) !== '' ? (string) $bracket['title'] : $token);

        return response()
            ->view('overlay.'.(((string) ($bracket['kind'] ?? 'bracket')) === 'table' ? 'table' : 'bracket'), [
                'title' => 'Overlay bracket - '.$name,
                'token' => $token,
                'version' => $this->effectiveVersion($bracket),
                'data' => ((string) ($bracket['kind'] ?? 'bracket')) === 'table'
                    ? $this->tableData($bracket)
                    : $this->bracketData($bracket),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * GET /bracket-overlay/{token}/version — version courante du payload,
     * interrogée par la page overlay pour se rafraîchir : version de
     * l'overlay lui-même, ou celle de la série attachée au match live si
     * elle est plus récente (un point marqué en cours de stream doit
     * rafraîchir le bracket sans intervention).
     */
    public function version(string $token): JsonResponse
    {
        $bracket = $this->brackets->find($token);
        if ($bracket === null) {
            abort(404);
        }

        return response()
            ->json(['version' => $this->effectiveVersion($bracket)])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Version effective d'un overlay : max(version propre, version de la
     * série attachée au match live).
     *
     * @param  array<string, mixed>  $bracket
     */
    private function effectiveVersion(array $bracket): int
    {
        $version = (int) ($bracket['version'] ?? 0);

        $live = $this->liveMatch($bracket);
        if ($live !== null && ! empty($live['series_token'])) {
            $series = $this->series->find((string) $live['series_token']);
            if ($series !== null) {
                $version = max($version, (int) ($series['version'] ?? 0));
            }
        }

        return $version;
    }

    /**
     * Match marqué « EN DIRECT » dans le payload, ou null.
     *
     * @param  array<string, mixed>  $bracket
     * @return array<string, mixed>|null
     */
    private function liveMatch(array $bracket): ?array
    {
        $live = $bracket['live'] ?? null;
        if (! is_array($live)) {
            return null;
        }

        foreach ($bracket['columns'] ?? [] as $column) {
            if (! is_array($column) || ($column['id'] ?? '') !== ($live['column'] ?? '')) {
                continue;
            }

            foreach ($column['matches'] ?? [] as $match) {
                if (is_array($match) && ($match['id'] ?? '') === ($live['match'] ?? '')) {
                    return $match;
                }
            }
        }

        return null;
    }

    /**
     * Prépare les données de rendu du bracket : colonnes en voie
     * upper/lower/final, statuts win/lose/pending déduits des scores, et
     * score du match live remplacé par celui de sa série attachée (sauf
     * score manuel forcé, pour rattraper une erreur de saisie).
     *
     * @param  array<string, mixed>  $bracket
     * @return array<string, mixed>
     */
    private function bracketData(array $bracket): array
    {
        $live = $this->liveMatch($bracket);
        $liveRef = is_array($bracket['live'] ?? null) ? $bracket['live'] : null;
        $seriesScore = null;

        // Score live de la série attachée : rouge → 1re équipe de la case,
        // bleu → 2de (l'ordre des équipes de la case est réglé côté admin).
        if ($live !== null && ! empty($live['series_token']) && empty($live['manual_scores'])) {
            $series = $this->series->find((string) $live['series_token']);
            if ($series !== null) {
                $state = (new SeriesScoreService)->compute($series);
                $seriesScore = [(int) $state['score']['red'], (int) $state['score']['blue']];
            }
        }

        $columns = [];
        foreach ($bracket['columns'] ?? [] as $column) {
            if (! is_array($column)) {
                continue;
            }

            $matches = [];
            foreach ($column['matches'] ?? [] as $match) {
                if (! is_array($match)) {
                    continue;
                }

                $scores = [$this->scoreString($match['teams'][0]['score'] ?? null), $this->scoreString($match['teams'][1]['score'] ?? null)];
                $isLive = $liveRef !== null
                    && ($liveRef['column'] ?? '') === ($column['id'] ?? '')
                    && ($liveRef['match'] ?? '') === ($match['id'] ?? '');

                if ($isLive && $seriesScore !== null) {
                    $scores = [(string) $seriesScore[0], (string) $seriesScore[1]];
                }

                $matches[] = [
                    'id' => (string) ($match['id'] ?? ''),
                    'live' => $isLive,
                    'teams' => [
                        $this->teamView($match['teams'][0] ?? [], $scores[0], $scores[1]),
                        $this->teamView($match['teams'][1] ?? [], $scores[1], $scores[0]),
                    ],
                ];
            }

            $columns[] = [
                'id' => (string) ($column['id'] ?? ''),
                'label' => (string) ($column['label'] ?? ''),
                'lane' => (string) ($column['lane'] ?? 'upper'),
                'matches' => $matches,
            ];
        }

        return [
            'eyebrow' => (string) ($bracket['eyebrow'] ?? ''),
            'title' => (string) ($bracket['title'] ?? ''),
            'accent' => (string) ($bracket['accent'] ?? ''),
            'columns' => $columns,
        ];
    }

    /**
     * Prépare les données de rendu de la table de classement : rang,
     * drapeau, surlignage du top X.
     *
     * @param  array<string, mixed>  $bracket
     * @return array<string, mixed>
     */
    private function tableData(array $bracket): array
    {
        $topX = (int) ($bracket['top_x'] ?? 0);

        $rows = [];
        $rank = 0;
        foreach ($bracket['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rank++;
            $rows[] = [
                'rank' => $rank,
                'name' => (string) ($row['name'] ?? ''),
                'avatar' => (string) ($row['avatar'] ?? ''),
                'played' => (int) ($row['played'] ?? 0),
                'won' => (int) ($row['won'] ?? 0),
                'lost' => (int) ($row['lost'] ?? 0),
                'score' => (int) ($row['score'] ?? 0),
                'penalty' => (int) ($row['penalty'] ?? 0),
                'qualified' => $topX > 0 && $rank <= $topX,
            ];
        }

        return [
            'eyebrow' => (string) ($bracket['eyebrow'] ?? ''),
            'title' => (string) ($bracket['title'] ?? ''),
            'accent' => (string) ($bracket['accent'] ?? ''),
            'show_penalty' => array_filter($rows, static fn (array $row): bool => $row['penalty'] !== 0) !== [],
            'rows' => $rows,
        ];
    }

    /**
     * Vue d'une équipe d'une case : nom, avatar et classe win/lose/pending
     * déduit des deux scores.
     *
     * @param  array<string, mixed>  $team
     */
    private function teamView(array $team, string $ownScore, string $otherScore): array
    {
        $status = 'pending';
        if ($ownScore !== '' && $otherScore !== '') {
            if ((int) $ownScore > (int) $otherScore) {
                $status = 'win';
            } elseif ((int) $ownScore < (int) $otherScore) {
                $status = 'lose';
            } else {
                $status = 'even';
            }
        }

        return [
            'name' => (string) ($team['name'] ?? ''),
            'avatar' => (string) ($team['avatar'] ?? ''),
            'score' => $ownScore,
            'status' => $status,
        ];
    }

    /**
     * Score sous forme de chaîne affichable ('' = pas encore joué).
     */
    private function scoreString(mixed $score): string
    {
        return is_numeric($score) ? (string) (int) $score : '';
    }
}
