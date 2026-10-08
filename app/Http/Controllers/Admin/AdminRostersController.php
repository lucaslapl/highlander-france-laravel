<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RostersRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\Etf2lTeamService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Outil « Overlay Rosters » : présentation des rosters des deux équipes
 * d'un match pour les broadcasts OBS, en Highlander (grille 3x3 des neuf
 * classes par équipe) comme en 6v6 (six classes alignées sur toute la
 * largeur). Accessible aux admins et aux rôles caster / prod, aux côtés
 * des autres outils overlay (middleware overlay-tools).
 *
 * Le remplissage est assisté : noms et avatars des équipes piochés dans
 * une compétition ETF2L du format du match (même conso modèle que les
 * autres outils), puis chaque classe reçoit son joueur via un menu
 * alimenté par le roster ETF2L de l'équipe. Tout reste modifiable à la
 * main (mercs, saisons passées) et chaque joueur peut être marqué
 * « merc » pour le badge doré de l'overlay. Le format est fixé à la
 * création : il fixe la disposition de l'overlay (3x3 ou six classes en
 * ligne), le changer reviendrait à vider les affectations de classes.
 */
final class AdminRostersController extends Controller
{
    private const MAX_TITLE_LEN = 96;

    private const MAX_NAME_LEN = 64;

    private const MAX_PLAYER_LEN = 40;

    private const MAX_URL_LEN = 500;

    /**
     * Formats disponibles : clé => libellé affiché, type de compétitions
     * ETF2L du remplissage assisté et classes jouées dans l'ordre
     * d'affichage de l'overlay (clés des portraits
     * public/_img/classes_portraits). Le 6v6 aligne deux Scouts et deux
     * Soldiers : les slots dupliqués sont numérotés côté éditeur
     * (« Scout 1 », « Scout 2 ») pour lever toute ambiguïté.
     */
    private const FORMATS = [
        'hl' => [
            'label' => 'Highlander (9 classes)',
            'competition_type' => 'Highlander',
            'classes' => ['scout', 'soldier', 'pyro', 'demoman', 'heavyweapons', 'engineer', 'medic', 'sniper', 'spy'],
        ],
        '6v6' => [
            'label' => '6v6 (6 classes)',
            'competition_type' => '6v6',
            'classes' => ['scout', 'scout', 'soldier', 'soldier', 'demoman', 'medic'],
        ],
    ];

    /** Libellés affichés des classes (mêmes clés que public/_img/classes). */
    private const CLASS_LABELS = [
        'scout' => 'Scout',
        'soldier' => 'Soldier',
        'pyro' => 'Pyro',
        'demoman' => 'Demoman',
        'heavyweapons' => 'Heavy',
        'engineer' => 'Engineer',
        'medic' => 'Medic',
        'sniper' => 'Sniper',
        'spy' => 'Spy',
    ];

    private RostersRepository $rosters;

    private Etf2lTeamService $etf2lTeams;

    public function __construct()
    {
        $this->rosters = new RostersRepository;
        $this->etf2lTeams = new Etf2lTeamService;
    }

    /**
     * GET /admin/overlay/rosters — liste des overlays + formulaire de
     * création.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.rosters', [
            'title' => 'Admin - Overlay Rosters (OBS)',
            'description' => 'Overlays de rosters d\'équipes pour les broadcasts OBS de Highlander France (Highlander et 6v6).',
            'rosters' => $this->rosters->all(),
            'formats' => $this->formatLabels(),
        ]);
    }

    /**
     * POST /admin/overlay/rosters/create — création manuelle d'un overlay :
     * les deux équipes vides, avec un slot de classe vide par joueur du
     * format (9 en Highlander, 6 en 6v6).
     */
    public function create(Request $request): RedirectResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'format' => ['required', 'in:'.implode(',', array_keys(self::FORMATS))],
            'eyebrow' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LEN],
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LEN],
        ]);

        $format = (string) $data['format'];

        $overlay = [
            'token' => bin2hex(random_bytes(8)),
            'format' => $format,
            'eyebrow' => trim((string) ($data['eyebrow'] ?? '')),
            'title' => trim((string) $data['title']),
            'teams' => [
                'a' => $this->emptyTeam(self::FORMATS[$format]['classes']),
                'b' => $this->emptyTeam(self::FORMATS[$format]['classes']),
            ],
            'created_at' => time(),
        ];

        $this->rosters->save($overlay);
        AdminLogger::log('admin_rosters_create', null, 'SUCCESS ('.$format.' '.$overlay['token'].' : '.$overlay['title'].')');

        return redirect('/admin/overlay/rosters/'.$overlay['token'])
            ->with('success', 'Overlay créé. Renseignez les équipes et affectez chaque classe ci-dessous.');
    }

    /**
     * GET /admin/overlay/rosters/{token} — éditeur. La liste des
     * compétitions ETF2L du format du match alimente le remplissage
     * assisté (noms / avatars + rosters pour l'affectation des classes) ;
     * l'indisponibilité de l'API n'empêche pas l'édition manuelle.
     */
    public function edit(string $token): View
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);
        $format = (string) ($overlay['format'] ?? 'hl');

        $competitions = [];
        try {
            $competitions = $this->etf2lTeams->competitions(self::FORMATS[$format]['competition_type']);
        } catch (\Throwable) {
            // API indisponible : le remplissage assisté sera masqué, le
            // reste de l'éditeur reste utilisable.
        }

        return view('admin.rosters_edit', [
            'title' => 'Admin - Overlay Rosters (OBS)',
            'description' => 'Édition de l\'overlay de rosters d\'équipes pour les broadcasts OBS.',
            'overlay' => $overlay,
            'overlay_url' => url('/roster-overlay/'.$overlay['token']),
            'competitions' => $competitions,
            'slots' => $this->slots($format),
        ]);
    }

    /**
     * POST /admin/overlay/rosters/{token}/update — enregistre le
     * formulaire d'édition : titres, équipes (nom + avatar + liaison
     * ETF2L) et l'affectation de chaque classe (pseudo, drapeau merc).
     * Le format n'est pas éditable : il fixe la disposition de l'overlay.
     */
    public function update(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);
        $format = (string) ($overlay['format'] ?? 'hl');

        $data = $request->validate([
            'eyebrow' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LEN],
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LEN],
        ]);

        $overlay['eyebrow'] = trim((string) ($data['eyebrow'] ?? ''));
        $overlay['title'] = trim((string) $data['title']);

        foreach (['a', 'b'] as $side) {
            $input = $request->input('team_'.$side, []);
            $input = is_array($input) ? $input : [];

            $etf2lId = (int) ($input['etf2l_id'] ?? 0);

            $overlay['teams'][$side] = [
                'name' => $this->clip((string) ($input['name'] ?? ''), self::MAX_NAME_LEN),
                'avatar' => $this->cleanUrl($input['avatar'] ?? null),
                'etf2l_id' => $etf2lId > 0 ? $etf2lId : null,
                'players' => $this->playersFromRequest($request, $side, $format),
            ];
        }

        $this->rosters->save($overlay);
        AdminLogger::log('admin_rosters_update', null, 'SUCCESS ('.$format.' '.$token.')');

        return back()->with('success', 'Overlay enregistré — l\'overlay OBS se rafraîchit aussitôt.');
    }

    /**
     * POST /admin/overlay/rosters/{token}/delete — supprime l'overlay.
     */
    public function delete(string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $this->requireOverlay($token);
        $this->rosters->delete($token);

        return redirect('/admin/overlay/rosters')->with('success', 'Overlay supprimé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOverlay(string $token): array
    {
        $overlay = $this->rosters->find($token);
        if ($overlay === null) {
            abort(404);
        }

        return $overlay;
    }

    /**
     * Équipe vide : un slot de classe par joueur du format, pseudo vide,
     * non merc. La classe de chaque slot vient du format, pas du
     * formulaire : l'ordre d'affichage de l'overlay est stable.
     *
     * @param  array<int, string>  $classes
     * @return array{name: string, avatar: string, etf2l_id: null, players: array<int, array{class: string, name: string, merc: bool}>}
     */
    private function emptyTeam(array $classes): array
    {
        return [
            'name' => '',
            'avatar' => '',
            'etf2l_id' => null,
            'players' => array_map(
                static fn (string $class): array => ['class' => $class, 'name' => '', 'merc' => false],
                $classes
            ),
        ];
    }

    /**
     * Affectations d'une équipe depuis le formulaire : un pseudo et un
     * drapeau merc par slot de classe, dans l'ordre du format. Les slots
     * manquants deviennent vides (format raccourci côté client), les
     * entrées surnuméraires sont ignorées.
     *
     * @return array<int, array{class: string, name: string, merc: bool}>
     */
    private function playersFromRequest(Request $request, string $side, string $format): array
    {
        $raw = $request->input('players_'.$side, []);
        $raw = is_array($raw) ? $raw : [];

        $out = [];
        foreach (self::FORMATS[$format]['classes'] as $i => $class) {
            $item = is_array($raw[$i] ?? null) ? $raw[$i] : [];

            $out[] = [
                'class' => $class,
                'name' => $this->clip((string) ($item['name'] ?? ''), self::MAX_PLAYER_LEN),
                'merc' => (bool) ($item['merc'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Slots de classe d'un format pour l'éditeur : clé de classe (portraits)
     * et libellé, les slots dupliqués du 6v6 numérotés pour l'admin.
     *
     * @return array<int, array{class: string, label: string}>
     */
    private function slots(string $format): array
    {
        $seen = [];
        $total = array_count_values(self::FORMATS[$format]['classes']);

        $slots = [];
        foreach (self::FORMATS[$format]['classes'] as $class) {
            $seen[$class] = ($seen[$class] ?? 0) + 1;
            $label = self::CLASS_LABELS[$class] ?? $class;
            if ($total[$class] > 1) {
                $label .= ' '.$seen[$class];
            }

            $slots[] = ['class' => $class, 'label' => $label];
        }

        return $slots;
    }

    /**
     * @return array<string, string>
     */
    private function formatLabels(): array
    {
        $out = [];
        foreach (self::FORMATS as $key => $format) {
            $out[$key] = $format['label'];
        }

        return $out;
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
}
