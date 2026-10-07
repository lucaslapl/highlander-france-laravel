<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BracketRepository;
use App\Models\SeriesRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\Etf2lTeamService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Outil « Overlay Bracket » : génération et édition des overlays de
 * playoffs (bracket) et de classements (table) pour les broadcasts OBS.
 * Accessible aux admins et aux rôles caster / prod, aux côtés des autres
 * outils overlay (middleware overlay-tools).
 *
 * Deux formats, même cycle de vie : création à la main d'un overlay
 * vide (un bracket démarre avec une colonne et une case, un classement
 * sans ligne), puis tout se construit et se renomme dans l'éditeur —
 * colonnes, labels de round, équipes, avatars, scores. Pas d'import
 * automatique des résultats : seuls les noms / avatars / pays des
 * équipes peuvent être piochés dans une compétition ETF2L (remplissage
 * assisté de l'éditeur).
 *
 * Le match diffusé en direct est désigné dans l'éditeur et peut être
 * attaché à une série (outil séries) : son score suit alors la série en
 * temps réel (points manuels, réconciliation logs.tf, webhook
 * match-ended), avec possibilité de forcer un score manuel en cas
 * d'erreur de saisie.
 */
final class AdminBracketController extends Controller
{
    private const MAX_NAME_LEN = 64;

    private const MAX_TITLE_LEN = 96;

    private const MAX_LABEL_LEN = 48;

    private const MAX_URL_LEN = 500;

    private BracketRepository $brackets;

    private SeriesRepository $series;

    private Etf2lTeamService $etf2lTeams;

    public function __construct()
    {
        $this->brackets = new BracketRepository;
        $this->series = new SeriesRepository;
        $this->etf2lTeams = new Etf2lTeamService;
    }

    /**
     * GET /admin/overlay/bracket — liste des overlays + création manuelle.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.bracket', [
            'title' => 'Admin - Overlay Bracket (OBS)',
            'description' => 'Overlays de brackets de playoffs et de classements pour les broadcasts OBS de Highlander France.',
            'brackets' => $this->brackets->all(),
        ]);
    }

    /**
     * POST /admin/overlay/bracket/create — création manuelle d'un overlay
     * vide (un bracket démarre avec une colonne et une case, un
     * classement sans ligne ; tout s'ajoute ensuite dans l'éditeur).
     */
    public function create(Request $request): RedirectResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'kind' => ['required', 'in:bracket,table'],
            'eyebrow' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LEN],
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LEN],
            'accent' => ['nullable', 'string', 'max:'.self::MAX_LABEL_LEN],
        ]);

        $kind = (string) $data['kind'];
        $bracket = [
            'token' => bin2hex(random_bytes(8)),
            'kind' => $kind,
            'eyebrow' => trim((string) ($data['eyebrow'] ?? '')),
            'title' => trim((string) $data['title']),
            'accent' => trim((string) ($data['accent'] ?? '')),
            'created_at' => time(),
        ];

        if ($kind === 'bracket') {
            $bracket['columns'] = [$this->emptyColumn('upper')];
            $bracket['live'] = null;
        } else {
            $bracket['rows'] = [];
            $bracket['top_x'] = 0;
        }

        $this->brackets->save($bracket);
        AdminLogger::log('admin_bracket_create', null, 'SUCCESS ('.$kind.' '.$bracket['token'].' : '.$bracket['title'].')');

        return redirect('/admin/overlay/bracket/'.$bracket['token'])
            ->with('success', 'Overlay créé. Ajoutez maintenant vos colonnes / lignes ci-dessous.');
    }

    /**
     * GET /admin/overlay/bracket/{token} — éditeur complet (bracket ou
     * classement selon le type de l'overlay). La liste des compétitions
     * ETF2L alimente le remplissage assisté (noms / avatars / pays) ;
     * l'indisponibilité de l'API n'empêche pas l'édition manuelle.
     */
    public function edit(string $token): View
    {
        Auth::requireOverlayTools();

        $bracket = $this->requireBracket($token);

        $competitions = [];
        try {
            $competitions = $this->etf2lTeams->competitions();
        } catch (\Throwable) {
            // API indisponible : le remplissage assisté sera masqué, le
            // reste de l'éditeur reste utilisable.
        }

        return view('admin.bracket_edit', [
            'title' => 'Admin - Overlay Bracket (OBS)',
            'description' => 'Édition de l\'overlay bracket / classement pour les broadcasts OBS.',
            'bracket' => $bracket,
            'overlay_url' => url('/bracket-overlay/'.$bracket['token']),
            'competitions' => $competitions,
            // Payloads complets (l'index des séries ne porte pas les équipes).
            'seriesList' => array_values(array_filter(array_map(
                fn (array $entry): ?array => $this->series->find((string) ($entry['token'] ?? '')),
                $this->series->all(),
            ))),
        ]);
    }

    /**
     * GET /admin/overlay/bracket/teams?competition_id=N — équipes d'une
     * compétition ETF2L (nom, avatar, pays) pour le remplissage assisté
     * de l'éditeur, interrogé en JavaScript. Vide si l'API est
     * indisponible ou la compétition inconnue.
     */
    public function teams(Request $request): JsonResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'competition_id' => ['required', 'integer', 'min:1'],
        ]);

        $teams = [];
        try {
            $teams = $this->etf2lTeams->teams((int) $data['competition_id']);
        } catch (\Throwable) {
            // API indisponible : liste vide, message d'erreur explicite.
        }

        return response()
            ->json(['teams' => $teams])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * POST /admin/overlay/bracket/{token}/update — enregistre le
     * formulaire d'édition : titres, colonnes (labels, voies, cases,
     * équipes, avatars, scores), attachement de série et score manuel du
     * match live, ou lignes du classement et top X.
     */
    public function update(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $bracket = $this->requireBracket($token);
        $kind = (string) ($bracket['kind'] ?? 'bracket');

        $data = $request->validate([
            'eyebrow' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LEN],
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LEN],
            'accent' => ['nullable', 'string', 'max:'.self::MAX_LABEL_LEN],
        ]);

        $bracket['eyebrow'] = trim((string) ($data['eyebrow'] ?? ''));
        $bracket['title'] = trim((string) $data['title']);
        $bracket['accent'] = trim((string) ($data['accent'] ?? ''));

        if ($kind === 'bracket') {
            $bracket['columns'] = $this->columnsFromRequest($request);
            $bracket['live'] = $this->liveFromRequest($request, $bracket['columns']);
        } else {
            $bracket['rows'] = $this->rowsFromRequest($request);
            $topX = (int) $request->input('top_x', 0);
            $bracket['top_x'] = max(0, min(20, $topX));
        }

        $this->brackets->save($bracket);
        AdminLogger::log('admin_bracket_update', null, 'SUCCESS ('.$kind.' '.$token.')');

        return back()->with('success', 'Overlay enregistré — l\'overlay OBS se rafraîchit aussitôt.');
    }

    /**
     * POST /admin/overlay/bracket/{token}/delete — supprime l'overlay.
     */
    public function delete(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $this->requireBracket($token);
        $this->brackets->delete($token);

        return redirect('/admin/overlay/bracket')->with('success', 'Overlay supprimé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireBracket(string $token): array
    {
        $bracket = $this->brackets->find($token);
        if ($bracket === null) {
            abort(404);
        }

        return $bracket;
    }

    /**
     * Reconstitue les colonnes du bracket depuis le formulaire d'édition.
     * L'ordre du formulaire fait l'ordre des colonnes et des cases (les
     * indices peuvent être non contigus : ajout/suppression en JavaScript
     * côté éditeur).
     *
     * @return array<int, array<string, mixed>>
     */
    private function columnsFromRequest(Request $request): array
    {
        $raw = $request->input('columns', []);
        if (! is_array($raw)) {
            return [];
        }

        ksort($raw, SORT_NUMERIC);

        $validSeries = [];
        foreach ($this->series->all() as $series) {
            $validSeries[(string) ($series['token'] ?? '')] = true;
        }

        $columns = [];
        foreach ($raw as $columnInput) {
            if (! is_array($columnInput)) {
                continue;
            }

            $matchesRaw = is_array($columnInput['matches'] ?? null) ? $columnInput['matches'] : [];
            ksort($matchesRaw, SORT_NUMERIC);

            $matches = [];
            foreach ($matchesRaw as $matchInput) {
                if (! is_array($matchInput)) {
                    continue;
                }

                $seriesToken = (string) ($matchInput['series_token'] ?? '');
                $matches[] = [
                    'id' => $this->clip((string) ($matchInput['id'] ?? ''), 16) ?: $this->newId('m'),
                    'teams' => [
                        $this->teamFromInput($matchInput, 1),
                        $this->teamFromInput($matchInput, 2),
                    ],
                    'series_token' => isset($validSeries[$seriesToken]) ? $seriesToken : null,
                    'manual_scores' => (string) ($matchInput['manual_scores'] ?? '') === '1',
                ];
            }

            $columns[] = [
                'id' => $this->clip((string) ($columnInput['id'] ?? ''), 16) ?: $this->newId('c'),
                'label' => $this->clip((string) ($columnInput['label'] ?? ''), self::MAX_LABEL_LEN),
                'lane' => in_array($columnInput['lane'] ?? '', ['upper', 'lower', 'final'], true) ? (string) $columnInput['lane'] : 'upper',
                'matches' => $matches,
            ];
        }

        return $columns;
    }

    /**
     * Équipe d'une case depuis le préfixe team1_ / team2_ du formulaire.
     *
     * @param  array<string, mixed>  $input
     * @return array{name: string, avatar: string, country: string, score: string}
     */
    private function teamFromInput(array $input, int $side): array
    {
        return [
            'name' => $this->clip((string) ($input['team'.$side.'_name'] ?? ''), self::MAX_NAME_LEN),
            'avatar' => $this->cleanUrl($input['team'.$side.'_avatar'] ?? null),
            'country' => $this->clip((string) ($input['team'.$side.'_country'] ?? ''), 32),
            'score' => $this->cleanScore($input['team'.$side.'_score'] ?? null),
        ];
    }

    /**
     * Sélection du match « EN DIRECT » depuis le formulaire : valeur
     * « idColonne:idCase », ou null si aucun.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<string, string>|null
     */
    private function liveFromRequest(Request $request, array $columns): ?array
    {
        $value = trim((string) $request->input('live_match', ''));
        if ($value === '') {
            return null;
        }

        [$columnId, $matchId] = array_pad(explode(':', $value, 2), 2, '');
        foreach ($columns as $column) {
            if ((string) ($column['id'] ?? '') !== $columnId) {
                continue;
            }

            foreach ($column['matches'] ?? [] as $match) {
                if ((string) ($match['id'] ?? '') === $matchId) {
                    return ['column' => $columnId, 'match' => $matchId];
                }
            }
        }

        return null;
    }

    /**
     * Reconstitue les lignes du classement depuis le formulaire d'édition
     * (l'ordre du formulaire fait le classement).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromRequest(Request $request): array
    {
        $raw = $request->input('rows', []);
        if (! is_array($raw)) {
            return [];
        }

        ksort($raw, SORT_NUMERIC);

        $rows = [];
        foreach ($raw as $input) {
            if (! is_array($input)) {
                continue;
            }

            $rows[] = [
                'name' => $this->clip((string) ($input['name'] ?? ''), self::MAX_NAME_LEN),
                'avatar' => $this->cleanUrl($input['avatar'] ?? null),
                'country' => $this->clip((string) ($input['country'] ?? ''), 32),
                'played' => $this->cleanInt($input['played'] ?? null),
                'won' => $this->cleanInt($input['won'] ?? null),
                'lost' => $this->cleanInt($input['lost'] ?? null),
                'score' => $this->cleanInt($input['score'] ?? null),
                'penalty' => $this->cleanInt($input['penalty'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * Colonne vide prête à éditer (création manuelle d'un bracket).
     *
     * @return array<string, mixed>
     */
    private function emptyColumn(string $lane): array
    {
        return [
            'id' => $this->newId('c'),
            'label' => '',
            'lane' => $lane,
            'matches' => [[
                'id' => $this->newId('m'),
                'teams' => [
                    ['name' => '', 'avatar' => '', 'country' => '', 'score' => ''],
                    ['name' => '', 'avatar' => '', 'country' => '', 'score' => ''],
                ],
                'series_token' => null,
                'manual_scores' => false,
            ]],
        ];
    }

    /**
     * Identifiant court unique pour une colonne ou une case.
     */
    private function newId(string $prefix): string
    {
        return $prefix.bin2hex(random_bytes(4));
    }

    /**
     * Coupe une chaîne en octets sans casser un caractère UTF-8.
     */
    private function clip(string $value, int $maxBytes): string
    {
        $value = trim($value);
        if (strlen($value) > $maxBytes) {
            $value = mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }

        return $value;
    }

    /**
     * Garde une URL http(s) raisonnable, sinon vide.
     */
    private function cleanUrl(mixed $url): string
    {
        $url = trim((string) (is_string($url) ? $url : ''));
        if ($url === '' || strlen($url) > self::MAX_URL_LEN || ! preg_match('~^https?://[\w.-]+~i', $url)) {
            return '';
        }

        return $url;
    }

    /**
     * Score de case : chiffres seuls (le vide signifie « pas encore
     * joué »).
     */
    private function cleanScore(mixed $score): string
    {
        $score = trim((string) (is_string($score) ? $score : ''));
        if ($score === '' || ! preg_match('/^\d{1,3}$/', $score)) {
            return '';
        }

        return $score;
    }

    /**
     * Entier positif plafonné, 0 si invalide.
     */
    private function cleanInt(mixed $value): int
    {
        $value = (int) $value;

        return max(0, min(9999, $value));
    }
}
