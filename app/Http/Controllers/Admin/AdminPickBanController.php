<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etf2lMapRepository;
use App\Models\PickBanRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\Etf2lTeamService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Outil « Overlay Pick/Ban » : génération et édition des overlays de
 * pick / ban de maps pour les broadcasts OBS. Accessible aux admins et
 * aux rôles caster / prod, aux côtés des autres outils overlay
 * (middleware overlay-tools).
 *
 * Deux formats stricts, six cartes : « 3_3 » (3 picks + 3 bans) et
 * « 5_1 » (5 picks + 1 ban). Le format contraint l'enregistrement : le
 * nombre de cartes PICK et de cartes BAN doit exactement correspondre,
 * sinon l'enregistrement est refusé. Chaque carte porte une map (nom +
 * capture), l'équipe qui a pick / ban (A ou B) et son action.
 *
 * Le remplissage est assisté : les noms et avatars des deux équipes
 * peuvent être piochés dans une compétition ETF2L (même conso modèle que
 * l'outil bracket), et les maps dans la table etf2l_maps (miniatures
 * importées via maps:import-thumbnails ou le panel maps). Tout reste
 * modifiable à la main.
 */
final class AdminPickBanController extends Controller
{
    private const MAX_TITLE_LEN = 96;

    private const MAX_NAME_LEN = 64;

    private const MAX_MAP_LEN = 64;

    private const MAX_URL_LEN = 500;

    /**
     * Formats disponibles : clé => [picks attendus, bans attendus].
     */
    private const FORMATS = [
        '3_3' => ['picks' => 3, 'bans' => 3],
        '5_1' => ['picks' => 5, 'bans' => 1],
    ];

    /**
     * Gabarit d'actions posé à la création (et proposé au changement de
     * format côté éditeur) : ordre de draft classique, ban d'abord puis
     * picks / bans entrelacés pour le 3_3, ban unique puis cinq picks
     * pour le 5_1. L'ordre reste ensuite libre à l'édition.
     */
    private const DEFAULT_ACTIONS = [
        '3_3' => ['ban', 'ban', 'pick', 'pick', 'ban', 'pick'],
        '5_1' => ['ban', 'pick', 'pick', 'pick', 'pick', 'pick'],
    ];

    private PickBanRepository $pickbans;

    private Etf2lTeamService $etf2lTeams;

    public function __construct()
    {
        $this->pickbans = new PickBanRepository;
        $this->etf2lTeams = new Etf2lTeamService;
    }

    /**
     * GET /admin/overlay/pickban — liste des overlays + création manuelle.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.pickban', [
            'title' => 'Admin - Overlay Pick/Ban (OBS)',
            'description' => 'Overlays de pick / ban de maps pour les broadcasts OBS de Highlander France.',
            'pickbans' => $this->pickbans->all(),
        ]);
    }

    /**
     * POST /admin/overlay/pickban/create — création manuelle d'un overlay :
     * six cartes vides avec le gabarit d'actions du format choisi.
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
                'a' => ['name' => '', 'avatar' => ''],
                'b' => ['name' => '', 'avatar' => ''],
            ],
            'cards' => array_map(
                static fn (string $action): array => ['map' => '', 'image' => '', 'action' => $action, 'team' => ''],
                self::DEFAULT_ACTIONS[$format]
            ),
            'created_at' => time(),
        ];

        $this->pickbans->save($overlay);
        AdminLogger::log('admin_pickban_create', null, 'SUCCESS ('.$format.' '.$overlay['token'].' : '.$overlay['title'].')');

        return redirect('/admin/overlay/pickban/'.$overlay['token'])
            ->with('success', 'Overlay créé. Renseignez les équipes et les cartes ci-dessous.');
    }

    /**
     * GET /admin/overlay/pickban/{token} — éditeur. La liste des
     * compétitions ETF2L alimente le remplissage assisté (noms / avatars)
     * et la table etf2l_maps le choix des maps (nom + miniature) ;
     * l'indisponibilité de l'API n'empêche pas l'édition manuelle.
     */
    public function edit(string $token): View
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        $competitions = [];
        try {
            $competitions = $this->etf2lTeams->competitions();
        } catch (\Throwable) {
            // API indisponible : le remplissage assisté sera masqué, le
            // reste de l'éditeur reste utilisable.
        }

        return view('admin.pickban_edit', [
            'title' => 'Admin - Overlay Pick/Ban (OBS)',
            'description' => 'Édition de l\'overlay de pick / ban de maps pour les broadcasts OBS.',
            'overlay' => $overlay,
            'overlay_url' => url('/pickban-overlay/'.$overlay['token']),
            'competitions' => $competitions,
            'maps' => $this->editorMaps(),
            'formats' => self::FORMATS,
            'defaultActions' => self::DEFAULT_ACTIONS,
        ]);
    }

    /**
     * POST /admin/overlay/pickban/{token}/update — enregistre le
     * formulaire d'édition : format, titres, équipes (nom + avatar) et
     * six cartes (map, capture, action PICK/BAN, équipe A/B).
     *
     * Le format est strict : le compte de cartes PICK et de cartes BAN
     * doit exactement correspondre au format (3 + 3 ou 5 + 1), sinon
     * l'enregistrement est refusé.
     */
    public function update(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        $data = $request->validate([
            'format' => ['required', 'in:'.implode(',', array_keys(self::FORMATS))],
            'eyebrow' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LEN],
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LEN],
        ]);

        $format = (string) $data['format'];
        [$expectedPicks, $expectedBans] = array_values(self::FORMATS[$format]);

        $cards = $this->cardsFromRequest($request);

        $picks = count(array_filter($cards, static fn (array $card): bool => $card['action'] === 'pick'));
        $bans = count(array_filter($cards, static fn (array $card): bool => $card['action'] === 'ban'));

        if ($picks !== $expectedPicks || $bans !== $expectedBans) {
            return back()->withInput()->with('error', sprintf(
                'Format « %d picks + %d bans » non respecté : %d carte(s) PICK et %d carte(s) BAN enregistrées dans le formulaire.',
                $expectedPicks,
                $expectedBans,
                $picks,
                $bans
            ));
        }

        $overlay['format'] = $format;
        $overlay['eyebrow'] = trim((string) ($data['eyebrow'] ?? ''));
        $overlay['title'] = trim((string) $data['title']);
        $overlay['teams'] = $this->teamsFromRequest($request);
        $overlay['cards'] = $cards;

        $this->pickbans->save($overlay);
        AdminLogger::log('admin_pickban_update', null, 'SUCCESS ('.$format.' '.$token.')');

        return back()->with('success', 'Overlay enregistré — l\'overlay OBS se rafraîchit aussitôt.');
    }

    /**
     * POST /admin/overlay/pickban/{token}/delete — supprime l'overlay.
     */
    public function delete(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $this->requireOverlay($token);
        $this->pickbans->delete($token);

        return redirect('/admin/overlay/pickban')->with('success', 'Overlay supprimé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOverlay(string $token): array
    {
        $overlay = $this->pickbans->find($token);
        if ($overlay === null) {
            abort(404);
        }

        return $overlay;
    }

    /**
     * Reconstitue les six cartes depuis le formulaire d'édition (l'ordre
     * du formulaire fait l'ordre d'affichage). Les cartes sans action
     * valide sont ignorées — le contrôle strict des comptes PICK / BAN
     * a lieu ensuite et garantit six cartes complètes à l'enregistrement.
     *
     * @return array<int, array{map: string, image: string, action: string, team: string}>
     */
    private function cardsFromRequest(Request $request): array
    {
        $raw = $request->input('cards', []);
        if (! is_array($raw)) {
            return [];
        }

        ksort($raw, SORT_NUMERIC);

        $cards = [];
        foreach ($raw as $input) {
            if (! is_array($input)) {
                continue;
            }

            $action = (string) ($input['action'] ?? '');
            if (! in_array($action, ['pick', 'ban'], true)) {
                continue;
            }

            $team = (string) ($input['team'] ?? '');
            if (! in_array($team, ['a', 'b'], true)) {
                $team = '';
            }

            $cards[] = [
                'map' => $this->clip((string) ($input['map'] ?? ''), self::MAX_MAP_LEN),
                'image' => $this->cleanUrl($input['image'] ?? null),
                'action' => $action,
                'team' => $team,
            ];
        }

        return $cards;
    }

    /**
     * Équipes A et B depuis le formulaire (nom + avatar).
     *
     * @return array{a: array{name: string, avatar: string}, b: array{name: string, avatar: string}}
     */
    private function teamsFromRequest(Request $request): array
    {
        $out = [];
        foreach (['a', 'b'] as $side) {
            $input = $request->input('team_'.$side, []);
            $out[$side] = [
                'name' => $this->clip((string) (is_array($input) ? ($input['name'] ?? '') : ''), self::MAX_NAME_LEN),
                'avatar' => $this->cleanUrl(is_array($input) ? ($input['avatar'] ?? null) : null),
            ];
        }

        return $out;
    }

    /**
     * Maps actives de la table etf2l_maps pour le remplissage assisté de
     * l'éditeur : nom, libellé affiché et URL de la miniature (copiée du
     * storage vers le dossier web si absente, comme la page publique des
     * maps). Le format (6v6 / HL 9v9) est volontairement omis du libellé
     * et la liste est triée alphabétiquement pour faciliter la sélection.
     *
     * @return array<int, array{name: string, label: string, image: string}>
     */
    private function editorMaps(): array
    {
        $out = [];
        foreach ((new Etf2lMapRepository)->activeByCategory() as $maps) {
            foreach ($maps as $map) {
                $thumbnail = trim((string) ($map['thumbnail'] ?? ''));
                $out[] = [
                    'name' => (string) $map['name'],
                    'label' => trim(((string) ($map['label'] ?? '')) !== '' ? (string) $map['label'] : (string) $map['name']),
                    'image' => $thumbnail !== '' ? $this->thumbnailUrl($thumbnail) : '',
                ];
            }
        }

        usort($out, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * URL publique d'une miniature (chemin relatif au disque public) ;
     * la copie storage → public suit le modèle de la page des maps.
     */
    private function thumbnailUrl(string $rel): string
    {
        $rel = ltrim($rel, '/');
        $storageAbs = storage_path('app/public/'.$rel);
        $publicAbs = public_path('storage/'.$rel);

        if (is_file($storageAbs) && ! is_file($publicAbs)) {
            @mkdir(dirname($publicAbs), 0755, true);
            @copy($storageAbs, $publicAbs);
        }

        return asset('storage/'.$rel);
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
