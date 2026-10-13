<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProgrammeRepository;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\Etf2lMatchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Outil « Overlay Programme » : écran d'attente / de présentation pour
 * les broadcasts OBS — horloge Europe/Paris, décompte avant le match
 * (date/heure cible ou durée relançable, ajustable à la minute près),
 * équipes du jour, bandeau de bienvenue avec diaporama de messages en
 * fondu et programme de la semaine. Accessible aux admins et aux rôles
 * caster / prod, aux côtés des autres outils overlay (middleware
 * overlay-tools).
 *
 * Le remplissage des équipes est assisté : un lien de match ETF2L
 * suffit à préremplir les équipes du jour ou une entrée du programme
 * (même conso modèle que l'outil Overlay Scores). Tout reste
 * modifiable à la main.
 */
final class AdminProgrammeController extends Controller
{
    private const MAX_NAME_LEN = 96;

    private const MAX_TEXT_LEN = 120;

    private const MAX_SMALL_LEN = 48;

    private const MAX_TEAM_LEN = 64;

    private const MAX_MESSAGES = 30;

    private const MAX_MESSAGE_LEN = 300;

    private const MAX_SCHEDULE = 12;

    /** Ajustement borné du décompte (± 6 h par clic). */
    private const MAX_ADJUST_S = 21600;

    private ProgrammeRepository $programmes;

    public function __construct()
    {
        $this->programmes = new ProgrammeRepository;
    }

    /**
     * GET /admin/overlay/programme — liste des overlays + création.
     */
    public function index(): View
    {
        Auth::requireOverlayTools();

        return view('admin.programme', [
            'title' => 'Admin - Overlay Programme (OBS)',
            'description' => 'Ecrans d\'attente / de présentation pour les broadcasts OBS de Highlander France.',
            'programmes' => $this->programmes->all(),
        ]);
    }

    /**
     * POST /admin/overlay/programme/create — création manuelle d'un
     * overlay, avec les valeurs par défaut de l'écran d'attente.
     */
    public function create(Request $request): RedirectResponse
    {
        Auth::requireOverlayTools();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
        ]);

        $overlay = [
            'token' => bin2hex(random_bytes(8)),
            'name' => trim((string) $data['name']),
            'welcome' => 'Bienvenue sur Highlander France !',
            'team_a' => '',
            'team_b' => '',
            'messages' => ['Tous nos matchs sont disponibles en rediffusion sur notre chaîne YouTube'],
            'slideshow' => ['display_s' => 8, 'fade_s' => 1],
            'countdown' => [
                'mode' => 'duration',
                'label' => 'Début dans',
                'duration_s' => 3600,
                'started_at' => time(),
                'adjust_s' => 0,
                'zero_text' => 'EN DIRECT',
            ],
            'schedule' => [],
            'created_at' => time(),
        ];

        $this->programmes->save($overlay);
        AdminLogger::log('admin_programme_create', null, 'SUCCESS ('.$overlay['token'].' : '.$overlay['name'].')');

        return redirect('/admin/overlay/programme/'.$overlay['token'])
            ->with('success', 'Overlay créé. Réglez le décompte, les équipes et le programme ci-dessous.');
    }

    /**
     * GET /admin/overlay/programme/{token} — éditeur.
     */
    public function edit(string $token): View
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        return view('admin.programme_edit', [
            'title' => 'Admin - Overlay Programme (OBS)',
            'description' => 'Édition de l\'écran d\'attente / de présentation pour les broadcasts OBS.',
            'overlay' => $overlay,
            'overlay_url' => url('/programme-overlay/'.$overlay['token']),
        ]);
    }

    /**
     * POST /admin/overlay/programme/{token}/update — enregistre le
     * formulaire d'édition : bandeau de bienvenue, décompte (date/heure
     * cible en Europe/Paris ou durée relancée à l'enregistrement),
     * équipes du jour, messages du diaporama et programme de la semaine.
     */
    public function update(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.self::MAX_NAME_LEN],
            'welcome' => ['required', 'string', 'max:'.self::MAX_TEXT_LEN],
            'team_a' => ['nullable', 'string', 'max:'.self::MAX_TEAM_LEN],
            'team_b' => ['nullable', 'string', 'max:'.self::MAX_TEAM_LEN],
            'countdown_mode' => ['required', 'in:datetime,duration'],
            'countdown_label' => ['nullable', 'string', 'max:'.self::MAX_SMALL_LEN],
            'countdown_date' => ['nullable', 'date_format:Y-m-d'],
            'countdown_time' => ['nullable', 'date_format:H:i'],
            'countdown_duration_min' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'countdown_zero_text' => ['nullable', 'string', 'max:'.self::MAX_SMALL_LEN],
            'messages' => ['nullable', 'string', 'max:12000'],
            'slideshow_display_s' => ['required', 'integer', 'min:2', 'max:120'],
            'slideshow_fade_s' => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        $mode = (string) $data['countdown_mode'];

        if ($mode === 'datetime' && (empty($data['countdown_date']) || empty($data['countdown_time']))) {
            return back()->withInput()
                ->with('error', 'Mode date/heure : la date et l\'heure du début de match sont requises.');
        }

        $countdown = [
            'mode' => $mode,
            'label' => trim((string) ($data['countdown_label'] ?? '')) !== ''
                ? trim((string) $data['countdown_label'])
                : 'Début dans',
            'zero_text' => trim((string) ($data['countdown_zero_text'] ?? '')) !== ''
                ? trim((string) $data['countdown_zero_text'])
                : 'EN DIRECT',
            'adjust_s' => 0,
        ];

        if ($mode === 'datetime') {
            // Heure locale France (Europe/Paris) convertie en timestamp
            // UTC : la page overlay n'a plus qu'une soustraction à faire.
            $start = new \DateTimeImmutable(
                (string) $data['countdown_date'].' '.(string) $data['countdown_time'],
                new \DateTimeZone('Europe/Paris')
            );

            $countdown['target_ts'] = $start->getTimestamp();
        } else {
            // Durée : le décompte repart de zéro à chaque enregistrement.
            $countdown['duration_s'] = ((int) ($data['countdown_duration_min'] ?? 60)) * 60;
            $countdown['started_at'] = time();
        }

        $overlay['name'] = trim((string) $data['name']);
        $overlay['welcome'] = trim((string) $data['welcome']);
        $overlay['team_a'] = $this->clip((string) ($data['team_a'] ?? ''), self::MAX_TEAM_LEN);
        $overlay['team_b'] = $this->clip((string) ($data['team_b'] ?? ''), self::MAX_TEAM_LEN);
        $overlay['messages'] = $this->messagesFromRequest((string) ($data['messages'] ?? ''));
        $overlay['slideshow'] = [
            'display_s' => (int) $data['slideshow_display_s'],
            'fade_s' => (int) $data['slideshow_fade_s'],
        ];
        $overlay['countdown'] = $countdown;
        $overlay['schedule'] = $this->scheduleFromRequest($request);

        $this->programmes->save($overlay);
        AdminLogger::log('admin_programme_update', null, 'SUCCESS ('.$token.')');

        return back()->with('success', 'Overlay enregistré — l\'overlay OBS se rafraîchit aussitôt.');
    }

    /**
     * POST /admin/overlay/programme/{token}/countdown — ajustement à la
     * volée du décompte sans recharger le formulaire : delta en secondes
     * (positif ou négatif, borné) cumulé dans l'offset, ou remise à zéro
     * de l'offset. Chaque ajustement bump la version : l'overlay OBS se
     * resynchronise dans les secondes qui suivent.
     */
    public function countdown(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $overlay = $this->requireOverlay($token);

        if ($request->boolean('reset')) {
            $overlay['countdown']['adjust_s'] = 0;
        } else {
            $data = $request->validate([
                'delta_s' => ['required', 'integer', 'min:-'.self::MAX_ADJUST_S, 'max:'.self::MAX_ADJUST_S],
            ]);

            $current = (int) ($overlay['countdown']['adjust_s'] ?? 0);
            $overlay['countdown']['adjust_s'] = max(
                -self::MAX_ADJUST_S,
                min(self::MAX_ADJUST_S, $current + (int) $data['delta_s'])
            );
        }

        $this->programmes->save($overlay);
        AdminLogger::log('admin_programme_countdown', null, 'SUCCESS ('.$token.' : '.(int) $overlay['countdown']['adjust_s'].'s)');

        return back()->with('success', 'Décompte ajusté — l\'overlay OBS se resynchronise aussitôt.');
    }

    /**
     * POST /admin/overlay/programme/{token}/delete — supprime l'overlay.
     */
    public function delete(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $this->requireOverlay($token);
        $this->programmes->delete($token);

        return redirect('/admin/overlay/programme')->with('success', 'Overlay supprimé.');
    }

    /**
     * GET /admin/overlay/programme/etf2l-match?url=… — match ETF2L
     * (équipes, compétition) pour le préremplissage des équipes du jour
     * et des entrées du programme, interrogé en JavaScript par
     * admin_programme.js. Match null (URL invalide, API indisponible) :
     * message explicite côté client, la saisie manuelle reste possible.
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

        $match = (new Etf2lMatchService)->match($matchId);
        if ($match === null) {
            return response()
                ->json(['match' => null, 'error' => 'Match ETF2L introuvable ou API indisponible, réessayez plus tard.'])
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        return response()
            ->json(['match' => $match])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOverlay(string $token): array
    {
        $overlay = $this->programmes->find($token);
        if ($overlay === null) {
            abort(404);
        }

        return $overlay;
    }

    /**
     * Messages du diaporama : un par ligne du textarea, vides filtrés.
     *
     * @return array<int, string>
     */
    private function messagesFromRequest(string $raw): array
    {
        $messages = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = $this->clip($line, self::MAX_MESSAGE_LEN);
            if ($line !== '') {
                $messages[] = $line;
                if (count($messages) >= self::MAX_MESSAGES) {
                    break;
                }
            }
        }

        return $messages;
    }

    /**
     * Entrées du programme de la semaine depuis le formulaire (l'ordre
     * du formulaire fait l'ordre d'affichage). Les entrées entièrement
     * vides sont ignorées.
     *
     * @return array<int, array{day: string, time: string, label: string, team_a: string, team_b: string}>
     */
    private function scheduleFromRequest(Request $request): array
    {
        $raw = $request->input('schedule', []);
        if (! is_array($raw)) {
            return [];
        }

        ksort($raw, SORT_NUMERIC);

        $entries = [];
        foreach ($raw as $input) {
            if (! is_array($input) || count($entries) >= self::MAX_SCHEDULE) {
                continue;
            }

            $entry = [
                'day' => $this->clip((string) ($input['day'] ?? ''), self::MAX_TEAM_LEN),
                'time' => $this->clip((string) ($input['time'] ?? ''), 16),
                'label' => $this->clip((string) ($input['label'] ?? ''), self::MAX_TEXT_LEN),
                'team_a' => $this->clip((string) ($input['team_a'] ?? ''), self::MAX_TEAM_LEN),
                'team_b' => $this->clip((string) ($input['team_b'] ?? ''), self::MAX_TEAM_LEN),
            ];

            if (implode('', $entry) !== '') {
                $entries[] = $entry;
            }
        }

        return $entries;
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
}
