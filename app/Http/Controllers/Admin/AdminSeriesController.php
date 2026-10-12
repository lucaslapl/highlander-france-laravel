<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etf2lMapRepository;
use App\Models\SeriesRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\Etf2lMatchService;
use App\Services\Etf2lTeamService;
use App\Services\SeriesScoreService;
use App\Services\SteamId;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Outil « Overlay Scores » : overlay de score d'une série de playoffs à
 * points manuels (chaque map gagnée est saisie à la main depuis le détail
 * de la série, chaque événement est visible et annulable dans le journal).
 *
 * La série est remplie depuis un lien de match ETF2L
 * (https://etf2l.org/matches/{id}/) : il génère le titre (« Compétition —
 * A vs B »), les noms d'équipes (acronyme « tag » ETF2L le cas échéant,
 * modifiable), leurs avatars et leurs rosters, et les maps jouées si
 * ETF2L les connaît — sinon elles sont choisies dans le menu des maps du
 * site, filtré par le format du match (Highlander → 9v9, 6v6 → 6v6). Les
 * équipes encore inconnues (TBD en playoffs) ou une API indisponible
 * laissent la saisie manuelle prendre le relais, et une série existante
 * peut être resynchronisée depuis ETF2L à tout moment. Accessible aux
 * admins et aux rôles caster / prod (hub des overlays
 * /admin/overlays-stream).
 */
final class AdminSeriesController extends Controller
{
    private const MAX_NAME_LEN = 64;

    private SeriesRepository $series;

    private Etf2lMatchService $etf2lMatches;

    private Etf2lTeamService $etf2lTeams;

    public function __construct()
    {
        $this->series = new SeriesRepository;
        $this->etf2lMatches = new Etf2lMatchService;
        $this->etf2lTeams = new Etf2lTeamService;
    }

    /**
     * Compétitions ETF2L (Highlander et 6v6) pour le remplissage assisté
     * manuel (repli quand un match n'est pas exploitable : liste vide si
     * l'API est indisponible, la boîte est masquée).
     *
     * @return array<int, array{id: int, name: string, archived: bool, type: string}>
     */
    private function competitions(): array
    {
        try {
            return $this->etf2lTeams->competitionsBothFormats();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Maps actives du site groupées par catégorie (6v6 / 9v9) pour le menu
     * déroulant des maps de la série.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function mapPools(): array
    {
        try {
            return (new Etf2lMapRepository)->activeByCategory();
        } catch (\Throwable) {
            return ['6v6' => [], '9v9' => []];
        }
    }

    /**
     * GET /admin/series — liste des séries + formulaire de création.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.series', [
            'title' => 'Admin - Overlay Scores',
            'description' => 'Overlay de score des séries de playoffs rempli depuis un match ETF2L, à points manuels.',
            'seriesList' => $this->series->all(),
            'competitions' => $this->competitions(),
            'mapPools' => $this->mapPools(),
        ]);
    }

    /**
     * GET /admin/series/etf2l-match?url=… — match ETF2L (équipes, avatars,
     * rosters, maps, compétition) pour le préremplissage du formulaire de
     * création, interrogé en JavaScript par admin_series_match.js. Match
     * null (URL invalide, API indisponible) : message explicite côté
     * client, la saisie manuelle reste possible.
     */
    public function etf2lMatch(Request $request): JsonResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'match_url' => ['required', 'string', 'max:500'],
        ]);

        $matchId = Etf2lMatchService::matchIdFromUrl((string) $data['match_url']);
        if ($matchId === null) {
            return response()
                ->json(['match' => null, 'error' => 'Lien de match ETF2L invalide : il doit ressembler à https://etf2l.org/matches/93233/.'])
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        $match = null;
        try {
            $match = $this->etf2lMatches->matchWithRosters($matchId);
        } catch (\Throwable) {
            // API indisponible : match null, message explicite côté client.
        }

        return response()
            ->json([
                'match' => $match,
                'error' => $match === null ? 'Match ETF2L introuvable ou API indisponible pour le moment : remplissez la série à la main, ou réessayez plus tard.' : null,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * POST /admin/series/create — crée une série depuis un lien de match
     * ETF2L (équipes, joueurs, maps).
     *
     * Le lien génère le titre et, si l'API répond, lie chaque équipe à son
     * identifiant ETF2L (rafraîchissement des rosters et resynchronisation
     * possibles après création) ; les champs préremplis restent corrigibles
     * avant envoi (mercs, TBD, API indisponible : saisie manuelle en repli).
     * Les SteamIDs acceptés : SteamID64, « STEAM_1:X:Y » ou « [U:1:N] »,
     * un par ligne ou séparés par des virgules. Les maps sont choisies dans
     * le menu du site ; le mode (double attaque / log unique) est déduit du
     * nom (pl_ et A/D connus en double attaque).
     */
    public function create(Request $request): RedirectResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'match_url' => ['required', 'string', 'max:500'],
            'format' => ['required', 'in:bo3,bo5,fixed'],
            'red_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'blue_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'red_players' => ['required', 'string', 'max:5000'],
            'blue_players' => ['required', 'string', 'max:5000'],
            'maps' => ['required', 'array', 'min:1'],
            'maps.*' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9_]+$/'],
            'red_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'blue_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'red_etf2l_id' => ['nullable', 'integer', 'min:1'],
            'blue_etf2l_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $matchId = Etf2lMatchService::matchIdFromUrl((string) $data['match_url']);
        if ($matchId === null) {
            return back()->with('error', 'Lien de match ETF2L invalide : il doit ressembler à https://etf2l.org/matches/93233/.');
        }

        $red = $this->parsePlayers((string) $data['red_players']);
        $blue = $this->parsePlayers((string) $data['blue_players']);
        if ($red['invalid'] !== []) {
            return back()->with('error', 'SteamIDs rouges invalides : '.implode(', ', $red['invalid']));
        }
        if ($blue['invalid'] !== []) {
            return back()->with('error', 'SteamIDs bleus invalides : '.implode(', ', $blue['invalid']));
        }
        if ($red['players'] === [] || $blue['players'] === []) {
            return back()->with('error', 'Chaque équipe doit avoir au moins un joueur de roster.');
        }

        $maps = $this->parseMaps((array) $data['maps']);
        if ($maps === []) {
            return back()->with('error', 'Aucune map valide : choisissez-les dans le menu des maps de la série.');
        }

        // Le match est relu côté serveur (cache court) : le titre et la
        // liaison des équipes ne viennent jamais du client seul. Une API
        // indisponible n'empêche pas la création : titre de repli sur
        // l'identifiant du match, liaison absente.
        $match = null;
        try {
            $match = $this->etf2lMatches->match($matchId);
        } catch (\Throwable) {
            // API indisponible : saisie manuelle, la série reste créable.
        }

        $token = bin2hex(random_bytes(8));
        $teams = [];
        foreach (['red', 'blue'] as $team) {
            $teams[$team] = [
                'name' => trim((string) $data[$team.'_name']),
                'players' => $team === 'red' ? $red['players'] : $blue['players'],
                'avatar_url' => $this->cleanUrl($data[$team.'_avatar_url'] ?? null),
                'etf2l_id' => (int) ($data[$team.'_etf2l_id'] ?? 0) ?: null,
                'etf2l_name' => $this->matchTeamName($match, (int) ($data[$team.'_etf2l_id'] ?? 0)),
            ];
        }

        $this->series->save([
            'token' => $token,
            'title' => $match !== null ? $match['title'] : 'Match ETF2L #'.$matchId,
            'etf2l_match_id' => $matchId,
            'category' => $match['category'] ?? null,
            'format' => (string) $data['format'],
            'wins_needed' => match ((string) $data['format']) {
                'bo3' => 2,
                'bo5' => 3,
                default => null,
            },
            'created_at' => time(),
            'teams' => $teams,
            'maps' => $maps,
            'journal' => [],
        ]);

        AdminLogger::log('admin_series_create', null, 'SUCCESS (série '.$token.' : match ETF2L #'.$matchId.')');

        return redirect('/admin/series/'.$token)->with('success', 'Série créée : les points de maps se saisissent dans le détail, un par map gagnée.');
    }

    /**
     * GET /admin/series/{token} — état complet de la série et actions manuelles.
     */
    public function show(string $token): View
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $state = (new SeriesScoreService)->compute($series);

        return view('admin.series_edit', [
            'title' => 'Admin - Overlay Scores : '.($series['title'] ?? ''),
            'description' => 'Overlay de score d\'une série de playoffs à points manuels.',
            'series' => $series,
            'state' => $state,
            'has_avatar' => [
                'red' => $this->series->hasAvatar($token, 'red'),
                'blue' => $this->series->hasAvatar($token, 'blue'),
            ],
            'competitions' => $this->competitions(),
            'mapPools' => $this->mapPools(),
        ]);
    }

    /**
     * POST /admin/series/{token}/avatar — upload de l'avatar d'une équipe
     * (affiché par l'overlay ; l'URL externe, si renseignée, prime sur
     * l'upload).
     */
    public function avatar(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        if ($this->series->find($token) === null) {
            abort(404);
        }

        $data = $request->validate([
            'team' => ['required', 'string', 'in:red,blue'],
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:'.$this->series->maxAvatarKb()],
        ]);

        if (! $this->series->saveAvatar($token, (string) $data['team'], $data['avatar'])) {
            return back()->with('error', 'Format d\'avatar non autorisé (jpeg, png, webp).');
        }

        return back()->with('success', 'Avatar enregistré.');
    }

    /**
     * POST /admin/series/{token}/avatar/delete — supprime l'avatar uploadé.
     */
    public function avatarDelete(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        if ($this->series->find($token) === null) {
            abort(404);
        }

        $data = $request->validate([
            'team' => ['required', 'string', 'in:red,blue'],
        ]);

        $this->series->deleteAvatar($token, (string) $data['team']);

        return back()->with('success', 'Avatar supprimé.');
    }

    /**
     * POST /admin/series/{token}/teams — renomme les équipes après création
     * (correction d'un acronyme erroné), met à jour leur avatar par URL et
     * leur liaison ETF2L : c'est cette liaison qui permet de rafraîchir les
     * rosters et de resynchroniser la série depuis ETF2L.
     */
    public function teams(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $data = $request->validate([
            'red_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'blue_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'red_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'blue_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'red_etf2l_id' => ['nullable', 'integer', 'min:1'],
            'blue_etf2l_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $series['teams']['red']['name'] = trim((string) $data['red_name']);
        $series['teams']['blue']['name'] = trim((string) $data['blue_name']);
        $series['teams']['red']['avatar_url'] = $this->cleanUrl($data['red_avatar_url'] ?? null);
        $series['teams']['blue']['avatar_url'] = $this->cleanUrl($data['blue_avatar_url'] ?? null);

        // Liaison ETF2L : sans saisie (équipes manuelles), la liaison
        // existante est conservée — le remplissage assisté ne la vide pas.
        foreach (['red', 'blue'] as $team) {
            $etf2lId = (int) ($data[$team.'_etf2l_id'] ?? 0);
            if ($etf2lId > 0) {
                $series['teams'][$team]['etf2l_id'] = $etf2lId;
            }
        }

        $this->series->save($series);

        AdminLogger::log('admin_series_teams', null, 'SUCCESS (série '.$token.' : renommage en '.$data['red_name'].' / '.$data['blue_name'].')');

        return back()->with('success', 'Noms d\'équipes mis à jour — l\'overlay se rafraîchit tout seul.');
    }

    /**
     * POST /admin/series/{token}/rosters — remplace le roster de chaque
     * équipe par son roster ETF2L courant (transferts entre saisons, joueur
     * ajouté en cours de saison, mercs officialisés). Nécessite la liaison
     * ETF2L de l'équipe (préremplissage depuis le match ou remplissage
     * assisté).
     */
    public function rosters(string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $updated = [];
        $missing = [];
        $failed = [];

        foreach (['red', 'blue'] as $team) {
            $etf2lId = (int) ($series['teams'][$team]['etf2l_id'] ?? 0);
            $name = (string) $series['teams'][$team]['name'];

            if ($etf2lId <= 0) {
                $missing[] = $name;

                continue;
            }

            try {
                $roster = $this->etf2lTeams->roster($etf2lId);
            } catch (\Throwable) {
                $roster = null;
            }

            if ($roster === null) {
                $failed[] = $name;

                continue;
            }

            $series['teams'][$team]['players'] = $roster['players'];
            $updated[] = $name.' ('.count($roster['players']).' joueurs)';
        }

        if ($updated === []) {
            $reason = $missing !== [] && $failed === []
                ? 'équipe(s) non liée(s) à ETF2L : '.implode(', ', $missing)
                : 'API ETF2L indisponible';

            return back()->with('error', 'Impossible de rafraîchir les rosters ('.$reason.').');
        }

        $this->series->save($series);

        $message = 'Rosters ETF2L mis à jour : '.implode(', ', $updated).'.';
        if ($failed !== []) {
            $message .= ' Roster indisponible pour '.implode(', ', $failed).', réessayez plus tard.';
        }
        if ($missing !== []) {
            $message .= ' Équipe(s) non liée(s) à ETF2L : '.implode(', ', $missing).'.';
        }

        AdminLogger::log('admin_series_rosters', null, 'SUCCESS (série '.$token.' : '.$message.')');

        return back()->with('success', $message);
    }

    /**
     * POST /admin/series/{token}/resync — resynchronise la série depuis
     * son match ETF2L (équipes TBD devenues connues, rosters, maps
     * révélées par le pick / ban, titre). Les noms affichés, avatars et
     * rosters sont remplacés par les données ETF2L actuelles (les
     * corrections manuelles d'acronyme sont à refaire après coup) ; les
     * maps sont uniquement ajoutées, jamais retirées — le score acquis
     * sur une map existante ne bouge pas.
     */
    public function resync(string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $matchId = (int) ($series['etf2l_match_id'] ?? 0);
        if ($matchId <= 0) {
            return back()->with('error', 'Cette série n\'est pas liée à un match ETF2L : resynchronisation impossible.');
        }

        $match = null;
        try {
            $match = $this->etf2lMatches->matchWithRosters($matchId);
        } catch (\Throwable) {
            $match = null;
        }
        if ($match === null) {
            return back()->with('error', 'Match ETF2L introuvable ou API indisponible pour le moment, réessayez plus tard.');
        }

        foreach (['red', 'blue'] as $i => $team) {
            $etf2lTeam = $match['teams'][$i] ?? null;
            if ($etf2lTeam === null) {
                continue;
            }

            $series['teams'][$team]['etf2l_id'] = (int) $etf2lTeam['id'];
            $series['teams'][$team]['etf2l_name'] = (string) $etf2lTeam['name'];
            $series['teams'][$team]['name'] = $etf2lTeam['tag'] !== '' ? $etf2lTeam['tag'] : (string) $etf2lTeam['name'];

            if ((string) ($etf2lTeam['avatar'] ?? '') !== '') {
                $series['teams'][$team]['avatar_url'] = (string) $etf2lTeam['avatar'];
            }

            if (($etf2lTeam['players'] ?? []) !== []) {
                $series['teams'][$team]['players'] = $etf2lTeam['players'];
            }
        }

        // Maps : seules les maps encore inconnues de la série sont
        // ajoutées (correspondance par préfixe, ex. « cp_steel » pour
        // « cp_steel_f12 ») — jamais de retrait, le score acquis tient.
        $addedMaps = [];
        foreach ($match['maps'] as $mapName) {
            if (SeriesScoreService::matchSeriesMap($series, $mapName) === null) {
                $series['maps'][] = ['name' => $mapName, 'mode' => SeriesScoreService::deriveMode($mapName)];
                $addedMaps[] = $mapName;
            }
        }

        if (($series['category'] ?? null) === null && ($match['category'] ?? null) !== null) {
            $series['category'] = $match['category'];
        }

        $series['title'] = (string) $match['title'];
        $this->series->save($series);

        $summary = 'équipes et rosters ETF2L à jour'.($addedMaps !== [] ? ', maps ajoutées : '.implode(', ', $addedMaps) : '');
        AdminLogger::log('admin_series_resync', null, 'SUCCESS (série '.$token.' : '.$summary.')');

        return back()->with('success', 'Série resynchronisée depuis ETF2L : '.$summary.'.');
    }

    /**
     * POST /admin/series/{token}/swap — intervertit les équipes A et B côté
     * personnalisation : noms, avatars par URL et avatars uploadés. Le
     * score acquis et les rosters ne bougent pas : ce bouton corrige
     * uniquement l'ordre d'affichage sur l'overlay si les côtés ont été
     * mal choisis à la création — même logique que l'outil Overlay Logs.
     */
    public function swap(string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $redName = (string) $series['teams']['red']['name'];
        $redAvatarUrl = $series['teams']['red']['avatar_url'] ?? null;

        $series['teams']['red']['name'] = (string) $series['teams']['blue']['name'];
        $series['teams']['red']['avatar_url'] = $series['teams']['blue']['avatar_url'] ?? null;
        $series['teams']['blue']['name'] = $redName;
        $series['teams']['blue']['avatar_url'] = $redAvatarUrl;

        $this->series->save($series);
        $this->series->swapAvatars($token);

        AdminLogger::log('admin_series_swap', null, 'SUCCESS (série '.$token.' : équipes interverties)');

        return back()->with('success', 'Équipes interverties : noms et avatars A / B échangés — l\'overlay se rafraîchit tout seul.');
    }

    /**
     * POST /admin/series/{token}/point — point marqué manuel (scoring live
     * d'une map à log unique, contestation, rattrapage). C'est un
     * événement du journal comme les autres, visible et annulable.
     *
     * Sur une map à points (KOTH, 5cp), le point s'accumule en score live :
     * la map n'est décidée qu'au winlimit (KOTH : 3 points, 5cp : 5), sauf
     * golden cap en attente que le point tranche.
     */
    public function point(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $data = $request->validate([
            'map' => ['required', 'string', 'max:128'],
            'team' => ['required', 'in:red,blue'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $known = false;
        foreach (is_array($series['maps'] ?? null) ? $series['maps'] : [] as $map) {
            if (($map['name'] ?? '') === $data['map']) {
                $known = true;
                break;
            }
        }
        if (! $known) {
            return back()->with('error', 'Map inconnue dans cette série.');
        }

        $this->series->appendEvent($token, [
            'type' => 'manual',
            'source' => 'manual',
            'map' => (string) $data['map'],
            'team' => (string) $data['team'],
            'note' => trim((string) ($data['note'] ?? '')),
        ]);

        AdminLogger::log('admin_series_point', null, 'SUCCESS (série '.$token.' : +1 '.$data['team'].' sur '.$data['map'].')');

        return back()->with('success', 'Point de map ajouté.');
    }

    /**
     * POST /admin/series/{token}/void — annule un événement du journal.
     * L'événement annulé reste visible pour l'audit mais ne compte plus.
     */
    public function void(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $data = $request->validate(['event' => ['required', 'string', 'max:16']]);

        $exists = false;
        foreach (is_array($series['journal'] ?? null) ? $series['journal'] : [] as $event) {
            if (($event['id'] ?? '') === $data['event']) {
                $exists = true;
                break;
            }
        }
        if (! $exists) {
            return back()->with('error', 'Événement inconnu.');
        }

        $this->series->appendEvent($token, [
            'type' => 'void',
            'source' => 'manual',
            'target' => (string) $data['event'],
            'note' => 'annulé par un admin',
        ]);

        AdminLogger::log('admin_series_void', null, 'SUCCESS (série '.$token.' : annulation '.$data['event'].')');

        return back()->with('success', 'Événement annulé.');
    }

    /**
     * POST /admin/series/{token}/map — ajoute une map à la série (BO qui
     * va à sa dernière map, map décidée en cours de série). La map vient
     * du menu des maps du site, filtré par le format du match.
     */
    public function addMap(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $data = $request->validate([
            'map' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9_]+$/'],
        ]);

        $mapName = strtolower((string) $data['map']);
        if (SeriesScoreService::matchSeriesMap($series, $mapName) !== null) {
            return back()->with('error', 'Cette map (ou une version proche) est déjà dans la série.');
        }

        $series['maps'] = is_array($series['maps'] ?? null) ? $series['maps'] : [];
        $series['maps'][] = ['name' => $mapName, 'mode' => SeriesScoreService::deriveMode($mapName)];
        $this->series->save($series);

        AdminLogger::log('admin_series_map_add', null, 'SUCCESS (série '.$token.' : map '.$mapName.' ajoutée)');

        return back()->with('success', 'Map ajoutée à la série.');
    }

    /**
     * POST /admin/series/{token}/map/delete — retire une map de la série,
     * uniquement si aucun événement du journal ne la référence (erreur de
     * saisie) : une map qui a compté ne disparaît jamais, son historique
     * d'audit prime.
     */
    public function removeMap(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $data = $request->validate([
            'map' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9_]+$/'],
        ]);

        $mapName = strtolower((string) $data['map']);
        $used = false;
        foreach (is_array($series['journal'] ?? null) ? $series['journal'] : [] as $event) {
            if ((string) ($event['map'] ?? '') === $mapName) {
                $used = true;
                break;
            }
        }

        $maps = [];
        $found = false;
        foreach (is_array($series['maps'] ?? null) ? $series['maps'] : [] as $map) {
            if (($map['name'] ?? '') === $mapName) {
                $found = true;

                continue;
            }
            $maps[] = $map;
        }

        if (! $found) {
            return back()->with('error', 'Map inconnue dans cette série.');
        }
        if ($used) {
            return back()->with('error', 'Cette map a déjà compté dans le journal : elle ne peut plus être retirée (annulez ses événements d\'abord).');
        }

        $series['maps'] = $maps;
        $this->series->save($series);

        AdminLogger::log('admin_series_map_delete', null, 'SUCCESS (série '.$token.' : map '.$mapName.' retirée)');

        return back()->with('success', 'Map retirée de la série.');
    }

    /**
     * POST /admin/series/{token}/delete — supprime la série.
     */
    public function delete(string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $this->series->delete($token);
        AdminLogger::log('admin_series_delete', null, 'SUCCESS (série '.$token.')');

        return redirect('/admin/series')->with('success', 'Série supprimée.');
    }

    /**
     * Nom complet ETF2L de l'équipe liée d'un côté de la série, depuis le
     * match relu côté serveur (null si le côté n'est pas lié ou si l'API
     * n'a pas répondu) : l'acronyme affiché reste modifiable, le nom
     * complet garde la référence ETF2L.
     *
     * @param  array<string, mixed>|null  $match
     */
    private function matchTeamName(?array $match, int $etf2lId): ?string
    {
        if ($match === null || $etf2lId <= 0) {
            return null;
        }

        foreach ($match['teams'] as $team) {
            if (is_array($team) && (int) $team['id'] === $etf2lId) {
                return (string) $team['name'];
            }
        }

        return null;
    }

    /**
     * Analyse une saisie de roster : éléments séparés par lignes, virgules ou
     * espaces, en SteamID64, STEAM_1:X:Y ou [U:1:N]. Retourne les SteamID64
     * uniques et les éléments invalides pour affichage.
     *
     * @return array{players: array<int, string>, invalid: array<int, string>}
     */
    private function parsePlayers(string $input): array
    {
        $players = [];
        $invalid = [];

        foreach (preg_split('/[\s,]+/', trim($input)) ?: [] as $item) {
            if ($item === '') {
                continue;
            }

            $steamid64 = $this->normalizeSteamId($item);
            if ($steamid64 === null) {
                $invalid[] = $item;
            } else {
                $players[$steamid64] = $steamid64;
            }
        }

        return ['players' => array_values($players), 'invalid' => $invalid];
    }

    private function normalizeSteamId(string $item): ?string
    {
        $item = trim($item);

        if (preg_match('/^\d{17}$/', $item)) {
            return $item;
        }

        $steam2 = SteamId::fromSteam2($item);
        if ($steam2 !== null) {
            return $steam2;
        }

        return SteamId::toSteamId64($item);
    }

    /**
     * Normalise une URL d'avatar saisie (vide => null).
     */
    private function cleanUrl(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || ! Str::startsWith($value, ['http://', 'https://'])) {
            return null;
        }

        return $value;
    }

    /**
     * Analyse les maps choisies dans le menu : noms seuls, mode déduit par
     * préfixe (pl_ et A/D connus en double attaque, le reste en points).
     *
     * @param  array<int, string>  $input
     * @return array<int, array{name: string, mode: string}>
     */
    private function parseMaps(array $input): array
    {
        $maps = [];

        foreach ($input as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === '' || preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
                continue;
            }

            $maps[$name] = ['name' => $name, 'mode' => SeriesScoreService::deriveMode($name)];
        }

        return array_values($maps);
    }
}
