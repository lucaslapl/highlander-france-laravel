<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth;
use App\Services\PlayerStatsService;
use App\Services\TeamStatsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Outil admin « Stats joueur / Équipe » (stats-joueur) : calcul de stats
 * (K/D, DPM, dégâts, soins, winrate) d'un joueur — ou de toute une équipe
 * via son roster ETF2L — à partir de logs logs.tf.
 *
 * Le calcul tourne en arrière-plan (commande artisan détachée) et écrit sa
 * progression dans un fichier JSON sous storage/app/hlfr/, que le navigateur
 * interroge par polling. Servira à présenter les joueurs/équipes sur les
 * streams Highlander France.
 */
final class AdminPlayerStatsController extends Controller
{
    /** Préfixes des fichiers de job de calcul. */
    private const PLAYER_PREFIX = 'player_stats_job_';

    private const TEAM_PREFIX = 'team_stats_job_';

    /** Durée de vie des jobs de calcul finis (nettoyage au prochain lancement). */
    private const JOB_TTL_S = 7200;

    public function index(): View
    {
        Auth::requireAdmin();

        return view('admin.player_stats', [
            'title' => 'Admin - Stats joueur / équipe (logs.tf)',
            'description' => 'Calcul de stats joueur (manuelles) ou d\'équipe (roster ETF2L) depuis des logs logs.tf, pour la présentation des matchs sur les streams.',
            'styles' => ['/_css/admin.css'],
            'scripts' => ['/_js/admin_player_stats.js'],
            'statLabels' => PlayerStatsService::STAT_LABELS,
        ]);
    }

    /**
     * POST /admin/stats-joueur/start — valide la saisie joueur puis lance le
     * calcul en arrière-plan. Répond avec le token du job.
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
        $requestFile = hlfr_data_path(self::PLAYER_PREFIX.$token.'.request.json');
        @file_put_contents($requestFile, json_encode([
            'steam' => (string) $data['steam'],
            'logs' => $logIds,
            'stats' => array_values($data['stats']),
        ]), LOCK_EX);

        $this->writeStatus(self::PLAYER_PREFIX, $token, [
            'status' => 'running',
            'logs_total' => count($logIds),
            'logs_done' => 0,
            'message' => 'Lancement du calcul…',
        ]);

        $this->deferSpawn(self::PLAYER_PREFIX, 'app:compute-player-stats', $token);

        return response()->json(['ok' => true, 'token' => $token, 'log_count' => count($logIds)]);
    }

    /**
     * POST /admin/stats-joueur/equipe/prepare — préparation synchrone d'une
     * équipe : roster ETF2L + compétitions (winrate officiel par mode) + logs
     * logs.tf découverts. Sert à remplir l'onglet avant le calcul.
     */
    public function prepareTeam(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        $data = $request->validate([
            'team' => ['required', 'integer', 'min:1'],
        ]);

        $service = new TeamStatsService;
        $teamId = (int) $data['team'];

        $roster = $service->fetchRoster($teamId);
        if ($roster === null) {
            return response()->json(['ok' => false, 'message' => "Équipe ETF2L $teamId introuvable ou impossible à récupérer."], 422);
        }

        $results = $service->fetchResults($teamId);
        $competitions = $service->groupCompetitions($teamId, $results);

        $steamid64s = array_column($roster['players'], 'steamid64');
        $logs = $service->discoverLogs($steamid64s);

        return response()->json([
            'ok' => true,
            'team' => $roster,
            'competitions' => $competitions,
            'logs' => $logs,
        ]);
    }

    /**
     * POST /admin/stats-joueur/equipe/start — lance le calcul des stats de
     * toute l'équipe en arrière-plan.
     */
    public function startTeam(Request $request): JsonResponse
    {
        Auth::requireAdmin();
        $this->purgeStaleJobs();

        $data = $request->validate([
            'team' => ['required', 'array'],
            'team.id' => ['required', 'integer', 'min:1'],
            'team.name' => ['required', 'string', 'max:255'],
            'players' => ['required', 'array'],
            'players.*.steamid64' => ['required', 'string', 'max:32'],
            'modes' => ['required', 'array'],
            'modes.9v9.*' => [],
            'modes.6s.*' => [],
        ]);

        $team = $data['team'];
        $players = $data['players'] ?? [];

        $modes = [];
        foreach (['9v9', '6s'] as $mode) {
            $cfg = $request->input("modes.$mode");
            if (! is_array($cfg)) {
                continue;
            }

            $logs = array_values(array_unique(array_filter(
                array_map('intval', (array) ($cfg['logs'] ?? [])),
                static fn (int $id): bool => $id > 0
            )));

            $competition = is_array($cfg['competition'] ?? null) ? $cfg['competition'] : null;
            if ($logs === [] && $competition === null) {
                continue;
            }

            $stats = array_values(array_intersect(
                array_keys(PlayerStatsService::STAT_LABELS),
                array_map('strval', (array) ($cfg['stats'] ?? []))
            ));

            $modes[$mode] = ['competition' => $competition, 'logs' => $logs, 'stats' => $stats];
        }

        if ($modes === []) {
            return response()->json(['ok' => false, 'message' => 'Sélectionnez au moins un log ou une compétition pour un mode.'], 422);
        }

        $token = bin2hex(random_bytes(8));
        $requestFile = hlfr_data_path(self::TEAM_PREFIX.$token.'.request.json');
        @file_put_contents($requestFile, json_encode([
            'team' => $team,
            'players' => $players,
            'modes' => $modes,
        ]), LOCK_EX);

        $totalLogs = array_sum(array_map(static fn (array $cfg): int => count($cfg['logs']), $modes));

        $this->writeStatus(self::TEAM_PREFIX, $token, [
            'status' => 'running',
            'logs_total' => $totalLogs,
            'logs_done' => 0,
            'message' => 'Lancement du calcul…',
        ]);

        $this->deferSpawn(self::TEAM_PREFIX, 'app:compute-team-stats', $token);

        return response()->json(['ok' => true, 'token' => $token, 'log_count' => $totalLogs, 'player_count' => count($players)]);
    }

    /**
     * GET /admin/stats-joueur/teams/search?q=... — autocomplete d'équipes ETF2L
     * locales (table etf2l_teams), pour l'onglet Équipe.
     */
    public function searchTeams(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $results = DB::table('etf2l_teams')
            ->where(function ($builder) use ($query): void {
                $builder->where('name', 'like', '%'.$query.'%')
                    ->orWhere('tag', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['team_id', 'name', 'tag', 'country'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->team_id,
                'name' => (string) $row->name,
                'tag' => $row->tag !== null ? (string) $row->tag : null,
                'country' => $row->country !== null ? (string) $row->country : null,
            ])
            ->all();

        return response()->json(array_values($results));
    }

    /**
     * GET /admin/stats-joueur/status/{token} — état d'un job (joueur ou équipe).
     */
    public function status(Request $request, string $token): JsonResponse
    {
        Auth::requireAdmin();

        foreach ([self::PLAYER_PREFIX, self::TEAM_PREFIX] as $prefix) {
            $statusFile = hlfr_data_path($prefix.$token.'.json');
            if (! is_file($statusFile)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($statusFile), true);

            return response()->json(is_array($data) ? $data : ['status' => 'error', 'error' => 'État du job corrompu.']);
        }

        return response()->json(['ok' => false, 'message' => 'Job introuvable.'], 404);
    }

    /**
     * Défère le spawn du job au callback « terminating » de Laravel, qui
     * s'exécute APRÈS l'envoi de la réponse HTTP au navigateur. Ainsi le
     * navigateur reçoit le token et démarre le polling immédiatement, même si
     * le calcul tourne en synchrone (exec/popen indisponible).
     */
    private function deferSpawn(string $prefix, string $command, string $token): void
    {
        app()->terminating(function () use ($prefix, $command, $token): void {
            $this->spawn($prefix, $command, $token);
        });
    }

    /**
     * Lance la commande de calcul en arrière-plan ; si le shell n'est pas
     * disponible (exec/popen désactivés sur le serveur), bascule sur une
     * exécution synchrone dans la requête plutôt que de bloquer le job.
     */
    private function spawn(string $prefix, string $command, string $token): void
    {
        $spawned = false;

        $binary = $this->cliBinary();
        if ($binary !== null) {
            $logFile = hlfr_data_path($prefix.$token.'.log');
            $cmd = escapeshellarg($binary)
                .' '.escapeshellarg(base_path('artisan'))
                .' '.$command.' '.escapeshellarg($token);

            if (DIRECTORY_SEPARATOR === '\\' && function_exists('popen')) {
                $proc = @popen('start /B '.$cmd.' > '.$logFile.' 2>&1', 'r');
                if (is_resource($proc)) {
                    @pclose($proc);
                    $spawned = true;
                }
            } elseif (DIRECTORY_SEPARATOR !== '\\' && function_exists('exec')) {
                @exec('nohup '.$cmd.' > '.$logFile.' 2>&1 &');
                $spawned = is_file($logFile);
            }
        }

        if (! $spawned) {
            try {
                Artisan::call($command, ['token' => $token]);
            } catch (\Throwable $e) {
                error_log('Échec du calcul de stats ('.$token.') : '.$e->getMessage());
                $this->writeStatus($prefix, $token, ['status' => 'error', 'error' => 'Impossible de lancer le calcul de stats.']);
            }
        }
    }

    /**
     * Chemin d'un binaire PHP CLI exécutable ou null si introuvable.
     */
    private function cliBinary(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }

        try {
            foreach ([
                defined('PHP_BINDIR') ? PHP_BINDIR.DIRECTORY_SEPARATOR.'php' : '',
                dirname((string) PHP_BINARY).DIRECTORY_SEPARATOR.'php',
                PHP_BINARY,
            ] as $candidate) {
                if ($candidate !== ''
                    && stripos(basename($candidate), 'fpm') === false
                    && @is_file($candidate)
                    && @is_executable($candidate)) {
                    return $candidate;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeStatus(string $prefix, string $token, array $data): void
    {
        $file = hlfr_data_path($prefix.$token.'.json');
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

        foreach ([self::PLAYER_PREFIX, self::TEAM_PREFIX] as $prefix) {
            foreach (glob(hlfr_data_path($prefix.'*.request.json')) ?: [] as $requestFile) {
                if (filemtime($requestFile) < $cutoff) {
                    $base = substr($requestFile, 0, -strlen('.request.json'));

                    @unlink($requestFile);
                    @unlink($base.'.json');
                    @unlink($base.'.log');
                }
            }
        }
    }
}
