<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProgrammeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Vue overlay publique « Programme » (OBS Studio, source navigateur
 * web), sur le même modèle que les overlays de stats, de série, de
 * bracket et de pick / ban : l'URL contient un token aléatoire (16
 * caractères) impossible à deviner, sans authentification par session
 * (OBS ne peut pas s'authentifier) et sans donnée sensible — noms
 * d'équipes, horaires et messages de programme publics.
 *
 * La page est entièrement transparente (1920x1080) et se rafraîchit
 * automatiquement via le polling de /programme-overlay/{token}/version :
 * chaque enregistrement (ou ajustement du décompte) côté admin bump la
 * version. Le contenu est rendu côté serveur ; seul
 * overlay_programme.js fait vivre la page : horloge Europe/Paris, tic
 * du décompte et diaporama de messages en fondu.
 */
final class ProgrammeOverlayController extends Controller
{
    private ProgrammeRepository $programmes;

    public function __construct()
    {
        $this->programmes = new ProgrammeRepository;
    }

    /**
     * GET /programme-overlay/{token} — page d'overlay (à pointer dans OBS).
     */
    public function show(string $token): Response
    {
        $overlay = $this->programmes->find($token);
        if ($overlay === null) {
            abort(404);
        }

        $name = trim(((string) ($overlay['name'] ?? '')) !== '' ? (string) $overlay['name'] : $token);

        return response()
            ->view('overlay.programme', [
                'title' => 'Overlay Programme - '.$name,
                'token' => $token,
                'version' => (int) ($overlay['version'] ?? 0),
                'data' => $this->viewData($overlay),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * GET /programme-overlay/{token}/version — version courante du
     * payload, interrogée par la page overlay pour se rafraîchir.
     */
    public function version(string $token): JsonResponse
    {
        $overlay = $this->programmes->find($token);
        if ($overlay === null) {
            abort(404);
        }

        return response()
            ->json(['version' => (int) ($overlay['version'] ?? 0)])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Prépare les données de rendu : décompte, équipes du jour, messages
     * du diaporama et programme de la semaine. Les valeurs sont bornées /
     * nettoyées une dernière fois : la page overlay est publique.
     *
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function viewData(array $overlay): array
    {
        $countdown = is_array($overlay['countdown'] ?? null) ? $overlay['countdown'] : [];

        $slideshow = is_array($overlay['slideshow'] ?? null) ? $overlay['slideshow'] : [];

        $messages = [];
        foreach (is_array($overlay['messages'] ?? null) ? $overlay['messages'] : [] as $message) {
            $message = trim((string) $message);
            if ($message !== '') {
                $messages[] = mb_strcut($message, 0, 300, 'UTF-8');
            }
        }

        $schedule = [];
        foreach (is_array($overlay['schedule'] ?? null) ? $overlay['schedule'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $day = trim((string) ($entry['day'] ?? ''));
            $time = trim((string) ($entry['time'] ?? ''));
            $teamA = trim((string) ($entry['team_a'] ?? ''));
            $teamB = trim((string) ($entry['team_b'] ?? ''));
            if ($day === '' && $time === '' && $teamA === '' && $teamB === '') {
                continue;
            }

            $schedule[] = [
                'day' => mb_strcut($day, 0, 64, 'UTF-8'),
                'time' => mb_strcut($time, 0, 16, 'UTF-8'),
                'label' => mb_strcut(trim((string) ($entry['label'] ?? '')), 0, 120, 'UTF-8'),
                'team_a' => mb_strcut($teamA, 0, 64, 'UTF-8'),
                'team_b' => mb_strcut($teamB, 0, 64, 'UTF-8'),
            ];
        }

        return [
            'welcome' => mb_strcut(trim((string) ($overlay['welcome'] ?? '')), 0, 120, 'UTF-8'),
            'messages' => $messages,
            'countdown_label' => mb_strcut(trim((string) ($countdown['label'] ?? '')), 0, 48, 'UTF-8'),
            'countdown_mode' => ($countdown['mode'] ?? 'duration') === 'datetime' ? 'datetime' : 'duration',
            'countdown_target_ts' => (int) ($countdown['target_ts'] ?? 0),
            'countdown_started_at' => (int) ($countdown['started_at'] ?? 0),
            'countdown_duration_s' => max(0, (int) ($countdown['duration_s'] ?? 0)),
            'countdown_adjust_s' => (int) ($countdown['adjust_s'] ?? 0),
            'countdown_zero_text' => mb_strcut(trim((string) ($countdown['zero_text'] ?? '')), 0, 48, 'UTF-8'),
            'team_a' => mb_strcut(trim((string) ($overlay['team_a'] ?? '')), 0, 64, 'UTF-8'),
            'team_b' => mb_strcut(trim((string) ($overlay['team_b'] ?? '')), 0, 64, 'UTF-8'),
            'slideshow_display_s' => max(2, min(120, (int) ($slideshow['display_s'] ?? 8))),
            'slideshow_fade_s' => max(0, min(10, (int) ($slideshow['fade_s'] ?? 1))),
            'schedule' => $schedule,
        ];
    }
}
