<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth;
use App\Services\PlayerStatsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

/**
 * Outil admin « Stats joueur » : calcul de stats (K/D, DPM, dégâts, soins,
 * winrate) d'un joueur à partir de logs logs.tf saisis manuellement.
 *
 * Le calcul tourne en arrière-plan (commande artisan détachée) et écrit sa
 * progression dans un fichier JSON sous storage/app/hlfr/, que le navigateur
 * interroge par polling. Servira plus tard à présenter les joueurs sur les
 * streams Highlander France.
 */
final class AdminPlayerStatsController extends Controller
{
    /** Durée de vie des jobs de calcul finis (nettoyage au prochain lancement). */
    private const JOB_TTL_S = 7200;

    public function index(): View
    {
        Auth::requireAdmin();

        return view('admin.player_stats', [
            'title' => 'Admin - Stats joueur (logs.tf)',
            'description' => 'Calcul de stats joueur depuis des logs logs.tf, pour la présentation des matchs sur les streams.',
            'styles' => ['/_css/admin.css'],
            'scripts' => ['/_js/admin_player_stats.js'],
            'statLabels' => PlayerStatsService::STAT_LABELS,
        ]);
    }

    /**
     * POST /admin/stats-joueur/start — valide la saisie puis lance le calcul
     * en arrière-plan. Répond avec le token du job.
     */
    public function start(Request $request): JsonResponse
    {
        Auth::requireAdmin();
        $this->purgeStaleJobs();

        $data = $request->validate([
            'steam' => ['required', 'string', 'max:255'],
            'logs' => ['required', 'array', 'min:1'],
            'logs.*' => ['required', 'string', 'max:255'],
            'stats' => ['required', 'array', 'min:1'],
            'stats.*' => ['required', 'string', Rule::in(array_keys(PlayerStatsService::STAT_LABELS))],
        ]);

        $service = new PlayerStatsService;

        $steamId3 = $service->resolvePlayer((string) $data['steam']);
        if ($steamId3 === null) {
            return response()->json([
                'ok' => false,
                'message' => 'SteamID invalide. Formats acceptés : SteamID64 (17 chiffres), SteamID3 ([U:1:x]), SteamID2 (STEAM_1:...) ou URL steamcommunity.com/profiles/<id>.',
            ], 422);
        }

        $logIds = [];
        foreach ($data['logs'] as $raw) {
            $id = $service->parseLogId((string) $raw);
            if ($id !== null) {
                $logIds[$id] = $id;
            }
        }
        $logIds = array_values($logIds);
        if ($logIds === []) {
            return response()->json([
                'ok' => false,
                'message' => 'Aucun ID ou URL logs.tf valide (ex : logs.tf/12345678).',
            ], 422);
        }

        $token = bin2hex(random_bytes(8));
        $requestFile = hlfr_data_path('player_stats_job_'.$token.'.request.json');
        @file_put_contents($requestFile, json_encode([
            'steam' => (string) $data['steam'],
            'logs' => $logIds,
            'stats' => array_values($data['stats']),
        ]), LOCK_EX);

        // État initial écrit par le contrôleur pour éviter un 404 pendant le
        // boot du processus en arrière-plan, puis le job prend le relais.
        $this->writeStatus($token, [
            'status' => 'running',
            'logs_total' => count($logIds),
            'logs_done' => 0,
            'message' => 'Lancement du calcul…',
        ]);

        $this->spawn($token);

        return response()->json(['ok' => true, 'token' => $token, 'log_count' => count($logIds)]);
    }

    /**
     * GET /admin/stats-joueur/status/{token} — état du job (progress + résultat).
     */
    public function status(Request $request, string $token): JsonResponse
    {
        Auth::requireAdmin();

        $statusFile = hlfr_data_path('player_stats_job_'.$token.'.json');
        if (! is_file($statusFile)) {
            return response()->json(['ok' => false, 'message' => 'Job introuvable.'], 404);
        }

        $data = json_decode((string) file_get_contents($statusFile), true);

        return response()->json(is_array($data) ? $data : ['status' => 'error', 'error' => 'État du job corrompu.']);
    }

    /**
     * Lance la commande de calcul en arrière-plan ; en dernier recours (spawn
     * impossible : exec/popen désactivés) exécution synchrone dans la requête.
     */
    private function spawn(string $token): void
    {
        $logFile = hlfr_data_path('player_stats_job_'.$token.'.log');
        $cmd = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg(base_path('artisan'))
            .' app:compute-player-stats '.escapeshellarg($token);

        $spawned = false;
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows (WAMP) : lancement détaché via le shell.
            $proc = @popen('start /B '.$cmd.' > '.$logFile.' 2>&1', 'r');
            if (is_resource($proc)) {
                @pclose($proc);
                $spawned = true;
            }
        } else {
            // Linux / WSL : processus détaché avec nohup.
            @exec('nohup '.$cmd.' > '.$logFile.' 2>&1 &');
            $spawned = is_file($logFile);
        }

        // Le fichier de log n'a pas pu être créé : exec/popen indisponibles.
        // On exécute le calcul de façon synchrone plutôt que de laisser le
        // job bloqué à l'état « running ».
        if (! $spawned) {
            try {
                Artisan::call('app:compute-player-stats', ['token' => $token]);
            } catch (\Throwable $e) {
                error_log('Échec du calcul de stats joueur ('.$token.') : '.$e->getMessage());
                $this->writeStatus($token, ['status' => 'error', 'error' => 'Impossible de lancer le calcul de stats.']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeStatus(string $token, array $data): void
    {
        $file = hlfr_data_path('player_stats_job_'.$token.'.json');
        $state = array_merge([
            'status' => 'running',
            'logs_total' => 0,
            'logs_done' => 0,
            'message' => '',
            'result' => null,
            'error' => null,
        ], $data);

        @file_put_contents($file, json_encode($state), LOCK_EX);
    }

    private function purgeStaleJobs(): void
    {
        $cutoff = time() - self::JOB_TTL_S;

        foreach (glob(hlfr_data_path('player_stats_job_*.request.json')) ?: [] as $requestFile) {
            if (filemtime($requestFile) < $cutoff) {
                $base = substr($requestFile, 0, -strlen('.request.json'));

                @unlink($requestFile);
                @unlink($base.'.json');
                @unlink($base.'.log');
            }
        }
    }
}
