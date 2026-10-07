<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SeriesRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\SeriesScoreService;
use App\Services\SteamId;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Outil « Overlay Scores » : overlay de score d'une série de playoffs avec
 * suivi automatique via logs.tf (réconciliateur app:series-reconcile), et
 * ajustements manuels (point de map, annulation d'un événement, renommage
 * des équipes) pour les contestations et les cas que l'automatisme ne couvre
 * pas. Accessible aux admins et aux rôles caster / prod (panel restreint
 * /admin/panel).
 */
final class AdminSeriesController extends Controller
{
    private const MAX_NAME_LEN = 64;

    private SeriesRepository $series;

    public function __construct()
    {
        $this->series = new SeriesRepository;
    }

    /**
     * GET /admin/series — liste des séries + formulaire de création.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.series', [
            'title' => 'Admin - Overlay Scores',
            'description' => 'Overlay de score des séries de playoffs avec suivi logs.tf automatique et ajustements manuels.',
            'seriesList' => $this->series->all(),
        ]);
    }

    /**
     * POST /admin/series/create — crée une série (équipes, joueurs, maps).
     *
     * Les noms d'équipes sont attendus sous forme d'acronyme (peu d'espace
     * sur l'overlay). Un à deux SteamIDs de joueurs du match par équipe
     * suffisent à retrouver les logs. Les SteamIDs acceptés : SteamID64,
     * « STEAM_1:X:Y » ou « [U:1:N] », un par ligne ou séparés par des
     * virgules. Les maps acceptent un mode explicite (« double » /
     * « single ») en second mot de la ligne, sinon le mode est déduit
     * (pl_ et A/D connus en double attaque).
     */
    public function create(Request $request): RedirectResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'format' => ['required', 'in:bo3,bo5,fixed'],
            'red_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'blue_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'red_players' => ['required', 'string', 'max:5000'],
            'blue_players' => ['required', 'string', 'max:5000'],
            'maps' => ['required', 'string', 'max:2000'],
            'red_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'blue_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
        ]);

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

        $maps = $this->parseMaps((string) $data['maps']);
        if ($maps === []) {
            return back()->with('error', 'Aucune map valide : une par ligne, ex. « pl_upward_f10 » ou « koth_product_final single ».');
        }

        $token = bin2hex(random_bytes(8));
        $this->series->save([
            'token' => $token,
            'title' => trim((string) $data['title']),
            'format' => (string) $data['format'],
            'wins_needed' => match ((string) $data['format']) {
                'bo3' => 2,
                'bo5' => 3,
                default => null,
            },
            'status' => 'upcoming',
            'started_at' => null,
            'created_at' => time(),
            'teams' => [
                'red' => ['name' => trim((string) $data['red_name']), 'players' => $red['players'], 'avatar_url' => $this->cleanUrl($data['red_avatar_url'] ?? null)],
                'blue' => ['name' => trim((string) $data['blue_name']), 'players' => $blue['players'], 'avatar_url' => $this->cleanUrl($data['blue_avatar_url'] ?? null)],
            ],
            'maps' => $maps,
            'journal' => [],
            'seen_logs' => [],
        ]);

        AdminLogger::log('admin_series_create', null, 'SUCCESS (série '.$token.' : '.trim((string) $data['title']).')');

        return redirect('/admin/series/'.$token)->with('success', 'Série créée. Attention : cliquez sur « Lancer le suivi » pour que l\'overlay soit actif et que la récupération des logs soit effective.');
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
            'description' => 'Overlay de score d\'une série de playoffs avec suivi logs.tf et ajustements manuels.',
            'series' => $series,
            'state' => $state,
            'has_avatar' => [
                'red' => $this->series->hasAvatar($token, 'red'),
                'blue' => $this->series->hasAvatar($token, 'blue'),
            ],
        ]);
    }

    /**
     * POST /admin/series/{token}/avatar — upload de l'avatar d'une équipe
     * (affiché par l'overlay de stats de la série ; l'URL externe, si
     * renseignée, prime sur l'upload).
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
     * POST /admin/series/{token}/overlay — avatars d'équipes par URL externe,
     * affichés par les overlays de la série (scoreboard et stats de match).
     */
    public function overlay(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $data = $request->validate([
            'red_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
            'blue_avatar_url' => ['nullable', 'url:http,https', 'max:500'],
        ]);

        $series['teams']['red']['avatar_url'] = $this->cleanUrl($data['red_avatar_url'] ?? null);
        $series['teams']['blue']['avatar_url'] = $this->cleanUrl($data['blue_avatar_url'] ?? null);
        $this->series->save($series);

        return back()->with('success', 'Avatars par URL enregistrés.');
    }

    /**
     * POST /admin/series/{token}/teams — renomme les équipes après création
     * (correction d'un acronyme erroné). Le nom s'affiche tel quel sur
     * l'overlay, d'où la forme d'acronyme attendue.
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
        ]);

        $series['teams']['red']['name'] = trim((string) $data['red_name']);
        $series['teams']['blue']['name'] = trim((string) $data['blue_name']);
        $this->series->save($series);

        AdminLogger::log('admin_series_teams', null, 'SUCCESS (série '.$token.' : renommage en '.$data['red_name'].' / '.$data['blue_name'].')');

        return back()->with('success', 'Noms d\'équipes mis à jour — l\'overlay se rafraîchit tout seul.');
    }

    /**
     * POST /admin/series/{token}/status — lance, arrête ou termine le suivi.
     *
     * « live » fige l'horodatage de référence : seuls les logs uploadés après
     * le lancement du suivi sont rattachés à la série.
     */
    public function status(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $data = $request->validate(['status' => ['required', 'in:upcoming,live,finished']]);

        if ($data['status'] === 'live' && ($series['started_at'] ?? null) === null) {
            $series['started_at'] = time();
        }
        $series['status'] = (string) $data['status'];
        $this->series->save($series);

        AdminLogger::log('admin_series_status', null, 'SUCCESS (série '.$token.' → '.$data['status'].')');

        return back()->with('success', 'Statut de la série mis à jour.');
    }

    /**
     * POST /admin/series/{token}/point — point de map manuel (contestation,
     * log manquant, rattrapage). Prioritaire sur l'automatique par construction :
     * c'est un événement du journal comme les autres, visible et annulable.
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
     * Analyse la saisie des maps : une par ligne, nom seul (mode déduit) ou
     * suivi du mode explicite (« double » / « single »).
     *
     * @return array<int, array{name: string, mode: string}>
     */
    private function parseMaps(string $input): array
    {
        $maps = [];

        foreach (explode("\n", $input) as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $name = strtolower((string) ($parts[0] ?? ''));
            if ($name === '') {
                continue;
            }

            if (! preg_match('/^[a-z0-9_]+$/', $name)) {
                continue;
            }

            $mode = strtolower((string) ($parts[1] ?? ''));
            if ($mode !== SeriesScoreService::MODE_DOUBLE && $mode !== SeriesScoreService::MODE_SINGLE) {
                $mode = SeriesScoreService::deriveMode($name);
            }

            $maps[$name] = ['name' => $name, 'mode' => $mode];
        }

        return array_values($maps);
    }
}
