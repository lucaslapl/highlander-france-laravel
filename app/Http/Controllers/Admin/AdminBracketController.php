<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BracketRepository;
use App\Models\SeriesRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\Etf2lCompetitionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Outil « Overlay Bracket » : génération et édition des overlays de
 * playoffs (bracket) et de classements (table) pour les broadcasts OBS.
 * Accessible aux admins et aux rôles caster / prod, aux côtés des autres
 * outils overlay (middleware overlay-tools).
 *
 * Deux formats, même cycle de vie : création à la main ou import depuis
 * l'API ETF2L v2 (compétition → résultats groupés par round pour un
 * bracket, compétition → table d'une division pour un classement), puis
 * tout reste éditable à la main — colonnes, labels de round, équipes,
 * avatars, scores. Le bouton « re-synchroniser » relit l'API ETF2L en
 * contournant le cache (les éditions manuelles post-import sont
 * conservées jusqu'au prochain import).
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

    private Etf2lCompetitionService $etf2l;

    public function __construct()
    {
        $this->brackets = new BracketRepository;
        $this->series = new SeriesRepository;
        $this->etf2l = new Etf2lCompetitionService;
    }

    /**
     * GET /admin/overlay/bracket — liste des overlays + création manuelle
     * + import ETF2L. La liste des compétitions est chargée côté serveur
     * (cache 6 h) ; l'indisponibilité de l'API n'empêche pas d'utiliser
     * le reste de la page.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        $competitions = [];
        try {
            $competitions = $this->etf2l->competitions();
        } catch (\Throwable) {
            // API indisponible : le formulaire d'import affichera un champ
            // d'identifiant libre, le reste de la page reste utilisable.
        }

        return view('admin.bracket', [
            'title' => 'Admin - Overlay Bracket (OBS)',
            'description' => 'Overlays de brackets de playoffs et de classements pour les broadcasts OBS de Highlander France.',
            'brackets' => $this->brackets->all(),
            'competitions' => $competitions,
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
            'etf2l' => null,
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
     * POST /admin/overlay/bracket/import — import ETF2L : les résultats
     * d'une compétition deviennent les colonnes du bracket (une par
     * round), ou la table d'une division devient le classement. Pour un
     * classement multi-divisions, un second pas propose le choix de la
     * division.
     */
    public function import(Request $request): RedirectResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'kind' => ['required', 'in:bracket,table'],
            'competition_id' => ['required', 'integer', 'min:1'],
            'division' => ['nullable', 'string', 'max:64'],
        ]);

        $kind = (string) $data['kind'];
        $competitionId = (int) $data['competition_id'];

        if ($kind === 'bracket') {
            $results = $this->etf2l->results($competitionId);
            if ($results === []) {
                return back()->with('error', "Aucun résultat exploitable pour la compétition ETF2L #$competitionId (peut-être pas encore jouée).");
            }

            $columns = [];
            foreach (Etf2lCompetitionService::bracketColumns($results) as $column) {
                $column['id'] = $this->newId('c');
                $column['matches'] = array_map(
                    fn (array $match): array => array_merge($match, [
                        'id' => $this->newId('m'),
                        'series_token' => null,
                        'manual_scores' => false,
                    ]),
                    $column['matches'],
                );
                $columns[] = $column;
            }

            $bracket = [
                'token' => bin2hex(random_bytes(8)),
                'kind' => 'bracket',
                'eyebrow' => 'ETF2L Highlander',
                'title' => $this->competitionTitle($competitionId),
                'accent' => '',
                'columns' => $columns,
                'live' => null,
                'etf2l' => ['competition_id' => $competitionId],
                'created_at' => time(),
            ];
        } else {
            $tables = $this->etf2l->tables($competitionId);
            $division = trim((string) ($data['division'] ?? ''));
            $divisionNames = array_keys($tables);

            if ($division === '' && count($divisionNames) > 1) {
                // Second pas : proposer le choix de la division.
                return back()
                    ->with('bracket_divisions', ['competition_id' => $competitionId, 'divisions' => $divisionNames])
                    ->withInput();
            }

            if ($division === '') {
                $division = $divisionNames[0] ?? '';
            }

            $rows = $tables[$division] ?? [];
            if ($rows === []) {
                return back()->with('error', "Aucune table de classement trouvée pour la compétition ETF2L #$competitionId.");
            }

            $bracket = [
                'token' => bin2hex(random_bytes(8)),
                'kind' => 'table',
                'eyebrow' => 'ETF2L Highlander',
                'title' => trim($this->competitionTitle($competitionId).' — Division '.$division),
                'accent' => '',
                'rows' => $rows,
                'top_x' => 0,
                'etf2l' => ['competition_id' => $competitionId, 'division' => $division],
                'created_at' => time(),
            ];
        }

        $this->brackets->save($bracket);
        AdminLogger::log('admin_bracket_import', null, 'SUCCESS ('.$kind.' '.$bracket['token'].' depuis ETF2L #'.$competitionId.')');

        return redirect('/admin/overlay/bracket/'.$bracket['token'])
            ->with('success', 'Overlay importé depuis l\'API ETF2L. Vérifiez les titres et ajustez à la main si besoin.');
    }

    /**
     * GET /admin/overlay/bracket/{token} — éditeur complet (bracket ou
     * classement selon le type de l'overlay).
     */
    public function edit(string $token): View
    {
        Auth::requireOverlayTools();

        $bracket = $this->requireBracket($token);

        return view('admin.bracket_edit', [
            'title' => 'Admin - Overlay Bracket (OBS)',
            'description' => 'Édition de l\'overlay bracket / classement pour les broadcasts OBS.',
            'bracket' => $bracket,
            'overlay_url' => url('/bracket-overlay/'.$bracket['token']),
            // Payloads complets (l'index des séries ne porte pas les équipes).
            'seriesList' => array_values(array_filter(array_map(
                fn (array $entry): ?array => $this->series->find((string) ($entry['token'] ?? '')),
                $this->series->all(),
            ))),
        ]);
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
     * POST /admin/overlay/bracket/{token}/resync — relit l'API ETF2L
     * (cache contourné) depuis la provenance de l'overlay : colonnes du
     * bracket ou lignes du classement remplacées, titres, token et type
     * conservés. Les identifiants étant régénérés, le match « EN DIRECT »
     * est réinitialisé.
     */
    public function resync(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $bracket = $this->requireBracket($token);
        $kind = (string) ($bracket['kind'] ?? 'bracket');
        $competitionId = (int) ($bracket['etf2l']['competition_id'] ?? 0);

        if ($competitionId === 0) {
            return back()->with('error', 'Cet overlay n\'a pas été importé depuis l\'API ETF2L : rien à re-synchroniser.');
        }

        if ($kind === 'bracket') {
            $results = $this->etf2l->results($competitionId, true);
            if ($results === []) {
                return back()->with('error', 'Aucun résultat exploitable côté ETF2L, réessayez plus tard.');
            }

            $columns = [];
            foreach (Etf2lCompetitionService::bracketColumns($results) as $column) {
                $column['id'] = $this->newId('c');
                $column['matches'] = array_map(
                    fn (array $match): array => array_merge($match, [
                        'id' => $this->newId('m'),
                        'series_token' => null,
                        'manual_scores' => false,
                    ]),
                    $column['matches'],
                );
                $columns[] = $column;
            }

            $bracket['columns'] = $columns;
            $bracket['live'] = null;
        } else {
            $division = (string) ($bracket['etf2l']['division'] ?? '');
            $tables = $this->etf2l->tables($competitionId, true);
            $rows = $tables[$division] ?? [];
            if ($rows === []) {
                return back()->with('error', 'Table introuvable côté ETF2L (division renommée ?), réessayez plus tard.');
            }

            $bracket['rows'] = $rows;
        }

        $this->brackets->save($bracket);
        AdminLogger::log('admin_bracket_resync', null, 'SUCCESS ('.$kind.' '.$token.' depuis ETF2L #'.$competitionId.')');

        return back()->with('success', 'Données re-synchronisées depuis l\'API ETF2L. Le match « EN DIRECT » a été réinitialisé (identifiants régénérés).');
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
     * Titre lisible d'une compétition pour préremplir l'overlay importé.
     */
    private function competitionTitle(int $competitionId): string
    {
        foreach ($this->etf2l->competitions() as $competition) {
            if ($competition['id'] === $competitionId) {
                return $competition['name'];
            }
        }

        return 'ETF2L #'.$competitionId;
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
