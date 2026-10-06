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

/**
 * Outil admin « Séries de matchs » : suivi automatique du score d'une série
 * de playoffs via logs.tf (réconciliateur app:series-reconcile), avec ajustements
 * manuels (point de map, annulation d'un événement) pour les contestations et
 * les cas que l'automatisme ne couvre pas.
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
        Auth::requireAdmin();

        return view('admin.series', [
            'title' => 'Admin - Séries de matchs (playoffs)',
            'description' => 'Suivi automatique du score des séries de playoffs via logs.tf, avec ajustements manuels.',
            'seriesList' => $this->series->all(),
        ]);
    }

    /**
     * POST /admin/series/create — crée une série (équipes, rosters, maps).
     *
     * Les SteamIDs acceptés : SteamID64, « STEAM_1:X:Y » ou « [U:1:N] », un
     * par ligne ou séparés par des virgules. Les maps acceptent un mode
     * explicite (« double » / « single ») en second mot de la ligne, sinon
     * le mode est déduit (pl_ et A/D connus en double attaque).
     */
    public function create(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'format' => ['required', 'in:bo3,bo5,fixed'],
            'red_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'blue_name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'red_players' => ['required', 'string', 'max:5000'],
            'blue_players' => ['required', 'string', 'max:5000'],
            'maps' => ['required', 'string', 'max:2000'],
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
                'red' => ['name' => trim((string) $data['red_name']), 'players' => $red['players']],
                'blue' => ['name' => trim((string) $data['blue_name']), 'players' => $blue['players']],
            ],
            'maps' => $maps,
            'journal' => [],
            'seen_logs' => [],
        ]);

        AdminLogger::log('admin_series_create', null, 'SUCCESS (série '.$token.' : '.trim((string) $data['title']).')');

        return redirect('/admin/series/'.$token)->with('success', 'Série créée. Lancez le suivi quand le match démarre.');
    }

    /**
     * GET /admin/series/{token} — état complet de la série et actions manuelles.
     */
    public function show(string $token): View
    {
        Auth::requireAdmin();

        $series = $this->series->find($token);
        if ($series === null) {
            abort(404);
        }

        $state = (new SeriesScoreService)->compute($series);

        return view('admin.series_edit', [
            'title' => 'Admin - Série : '.($series['title'] ?? ''),
            'description' => 'Suivi du score d\'une série de playoffs avec ajustements manuels.',
            'series' => $series,
            'state' => $state,
        ]);
    }

    /**
     * POST /admin/series/{token}/status — lance, arrête ou termine le suivi.
     *
     * « live » fige l'horodatage de référence : seuls les logs uploadés après
     * le lancement du suivi sont rattachés à la série.
     */
    public function status(Request $request, string $token): RedirectResponse
    {
        Auth::requireAdmin();

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
        Auth::requireAdmin();

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
        Auth::requireAdmin();

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
        Auth::requireAdmin();

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
