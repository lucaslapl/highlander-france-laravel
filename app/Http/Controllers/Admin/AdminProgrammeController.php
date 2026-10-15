<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etf2lRepository;
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

    private const MAX_URL_LEN = 500;

    /** Prochains matchs ETF2L proposés à l'import, au plus. */
    private const MAX_UPCOMING = 12;

    /** Jours de la semaine en français (formatage du programme). */
    private const FR_DAYS = [
        'Monday' => 'Lundi', 'Tuesday' => 'Mardi', 'Wednesday' => 'Mercredi',
        'Thursday' => 'Jeudi', 'Friday' => 'Vendredi', 'Saturday' => 'Samedi',
        'Sunday' => 'Dimanche',
    ];

    /** Mois de l'année en français (formatage du programme). */
    private const FR_MONTHS = [
        'January' => 'Janvier', 'February' => 'Février', 'March' => 'Mars',
        'April' => 'Avril', 'May' => 'Mai', 'June' => 'Juin',
        'July' => 'Juillet', 'August' => 'Août', 'September' => 'Septembre',
        'October' => 'Octobre', 'November' => 'Novembre', 'December' => 'Décembre',
    ];

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
            'template' => $this->programmes->findDefaultTemplate(),
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
            'name' => $this->clip((string) $data['name'], self::MAX_NAME_LEN),
            'welcome' => 'Bienvenue sur Highlander France !',
            'team_a' => '',
            'team_b' => '',
            'team_a_avatar' => '',
            'team_b_avatar' => '',
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

        if (! $this->programmes->save($overlay)) {
            AdminLogger::log('admin_programme_create', null, 'FAILURE ('.$overlay['token'].' : écriture impossible)');

            return redirect('/admin/overlay/programme')
                ->with('error', 'Création impossible : l\'écriture du fichier de l\'overlay a échoué (vérifiez les droits sur storage/app/hlfr/programmes).');
        }

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
            'team_a_avatar' => ['nullable', 'string', 'max:'.self::MAX_URL_LEN],
            'team_b_avatar' => ['nullable', 'string', 'max:'.self::MAX_URL_LEN],
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
                ? $this->clip((string) $data['countdown_label'], self::MAX_SMALL_LEN)
                : 'Début dans',
            'zero_text' => trim((string) ($data['countdown_zero_text'] ?? '')) !== ''
                ? $this->clip((string) $data['countdown_zero_text'], self::MAX_SMALL_LEN)
                : 'EN DIRECT',
            'adjust_s' => 0,
        ];

        // Les réglages du mode inactif (cible date/heure ou durée) sont
        // repris de l'état stocké : changer de mode à l'enregistrement
        // ne doit pas effacer ce qui avait été saisi pour l'autre mode —
        // l'éditeur re-préremplit ces champs au prochain affichage au
        // lieu de les remettre à vide.
        $stored = is_array($overlay['countdown'] ?? null) ? $overlay['countdown'] : [];
        if (isset($stored['target_ts'])) {
            $countdown['target_ts'] = (int) $stored['target_ts'];
        }
        if (isset($stored['duration_s'])) {
            $countdown['duration_s'] = (int) $stored['duration_s'];
        }

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

        $overlay['name'] = $this->clip((string) $data['name'], self::MAX_NAME_LEN);
        $overlay['welcome'] = $this->clip((string) $data['welcome'], self::MAX_TEXT_LEN);
        $overlay['team_a'] = $this->clip((string) ($data['team_a'] ?? ''), self::MAX_TEAM_LEN);
        $overlay['team_b'] = $this->clip((string) ($data['team_b'] ?? ''), self::MAX_TEAM_LEN);
        $overlay['team_a_avatar'] = $this->cleanUrl($data['team_a_avatar'] ?? null);
        $overlay['team_b_avatar'] = $this->cleanUrl($data['team_b_avatar'] ?? null);
        $overlay['messages'] = $this->messagesFromRequest((string) ($data['messages'] ?? ''));
        $overlay['slideshow'] = [
            'display_s' => (int) $data['slideshow_display_s'],
            'fade_s' => (int) $data['slideshow_fade_s'],
        ];
        $overlay['countdown'] = $countdown;
        $overlay['schedule'] = $this->scheduleFromRequest($request);

        if (! $this->programmes->save($overlay)) {
            // Écriture impossible (répertoire non inscriptible, JSON
            // invalide) : sans ce retour, le message « enregistré »
            // mentait et le formulaire revenait à son état d'avant.
            AdminLogger::log('admin_programme_update', null, 'FAILURE ('.$token.' : écriture impossible)');

            return back()->withInput()
                ->with('error', 'Enregistrement impossible : l\'écriture du fichier de l\'overlay a échoué (vérifiez les droits sur storage/app/hlfr/programmes).');
        }

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

        if (! $this->programmes->save($overlay)) {
            AdminLogger::log('admin_programme_countdown', null, 'FAILURE ('.$token.' : écriture impossible)');

            return back()->with('error', 'Ajustement impossible : l\'écriture du fichier de l\'overlay a échoué.');
        }

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
        if (! $this->programmes->delete($token)) {
            return redirect('/admin/overlay/programme')
                ->with('error', 'Suppression impossible : l\'écriture a échoué (vérifiez les droits sur storage/app/hlfr/programmes).');
        }

        return redirect('/admin/overlay/programme')->with('success', 'Overlay supprimé.');
    }

    /**
     * POST /admin/overlay/programme/{token}/duplicate — duplique l'overlay
     * (copie intégrale sous un nouveau token) et ouvre la copie dans
     * l'éditeur : l'original reste intact.
     */
    public function duplicate(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $this->requireOverlay($token);

        $copy = $this->programmes->duplicate($token);
        if ($copy === null) {
            return redirect('/admin/overlay/programme')
                ->with('error', 'Duplication impossible : l\'écriture a échoué (vérifiez les droits sur storage/app/hlfr/programmes).');
        }

        AdminLogger::log('admin_programme_duplicate', null, 'SUCCESS ('.$token.' → '.$copy['token'].')');

        return redirect('/admin/overlay/programme/'.$copy['token'])
            ->with('success', 'Overlay dupliqué : voici la copie, l\'original est intact. Réglez-la ci-dessous.');
    }

    /**
     * POST /admin/overlay/programme/{token}/template — désigne l'overlay
     * comme « modèle par défaut » de l'outil (unique) : le bouton
     * « Dupliquer le modèle » de la zone de création le reprend.
     */
    public function setDefaultTemplate(Request $request, string $token): RedirectResponse
    {
        Auth::requireOverlayTools();

        $this->requireOverlay($token);

        if (! $this->programmes->setDefaultTemplate($token)) {
            return redirect('/admin/overlay/programme')
                ->with('error', 'Désignation impossible : l\'écriture a échoué (vérifiez les droits sur storage/app/hlfr/programmes).');
        }

        AdminLogger::log('admin_programme_template', null, 'SUCCESS ('.$token.')');

        return redirect('/admin/overlay/programme')
            ->with('success', 'Overlay désigné comme modèle par défaut : le bouton « Dupliquer le modèle » de la création le reprend.');
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
     * GET /admin/overlay/programme/upcoming — prochains matchs des
     * équipes françaises (table etf2l_matches synchronisée par le cron
     * app:update-stats depuis l'API ETF2L, jamais d'appel HTTP ici),
     * normalisés en entrées prêtes pour le programme de la semaine :
     * date et heure en français, compétition en libellé, noms des deux
     * équipes. Interrogé en JavaScript par admin_programme.js ; tout
     * reste corrigeable à la main une fois l'entrée ajoutée.
     */
    public function upcoming(): JsonResponse
    {
        Auth::requireOverlayTools();

        $out = [];
        foreach ((new Etf2lRepository)->upcomingMatches(self::MAX_UPCOMING) as $match) {
            // Les noms stockés par la synchro peuvent contenir des
            // entités HTML (« &#039; ») : même décodage que l'import
            // direct, l'entrée arrive propre dans le formulaire.
            $out[] = [
                'day' => $this->frenchDay((int) ($match['match_date'] ?? 0)),
                'time' => $this->frenchHour((int) ($match['match_date'] ?? 0)),
                'label' => trim((string) ($match['competition_name'] ?? '')) !== ''
                    ? $this->clip((string) $match['competition_name'], self::MAX_TEXT_LEN)
                    : 'ETF2L',
                'team_a' => trim((string) ($match['team1_name'] ?? '')) !== '' ? $this->clip((string) $match['team1_name'], self::MAX_TEAM_LEN) : 'TBD',
                'team_b' => trim((string) ($match['team2_name'] ?? '')) !== '' ? $this->clip((string) $match['team2_name'], self::MAX_TEAM_LEN) : 'TBD',
                'match_url' => 'https://etf2l.org/matches/'.(int) ($match['match_id'] ?? 0).'/',
            ];
        }

        return response()
            ->json(['matches' => $out])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Date française « Dimanche 11 Octobre » depuis un timestamp (heure
     * Europe/Paris), sans dépendre d'une locale système installée.
     */
    private function frenchDay(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        $date = (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Europe/Paris'));
        $day = self::FR_DAYS[$date->format('l')] ?? $date->format('l');
        $month = self::FR_MONTHS[$date->format('F')] ?? $date->format('F');

        return $day.' '.$date->format('j').' '.$month;
    }

    /**
     * Heure française « 21h05 » depuis un timestamp (Europe/Paris).
     */
    private function frenchHour(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        $date = (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Europe/Paris'));

        return $date->format('G').'h'.$date->format('i');
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
     * Coupe une chaîne en octets sans casser un caractère UTF-8. Les
     * entités HTML sont d'abord décodées : les données saisies avant le
     * correctif d'échappement des vues (double échappement de {{
     * e(…) }}, apostrophes stockées en « &#039; ») se réparent d'elles-
     * mêmes à l'enregistrement suivant, et un texte collé avec des
     * entités repart propre. La vue ré-échappe à l'affichage.
     */
    private function clip(string $value, int $maxBytes): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
