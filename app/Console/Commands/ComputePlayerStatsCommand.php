<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlayerStatsService;
use Illuminate\Console\Command;

/**
 * Calcul des stats joueur depuis des logs logs.tf saisis sur la page
 * admin /admin/stats-joueur. Lancé en arrière-plan (procédé détaché) et non
 * programmé : il lit la requête dans un fichier JSON puis écrit la progression
 * et le résultat dans un autre fichier JSON (storage/app/hlfr/).
 */
final class ComputePlayerStatsCommand extends Command
{
    protected $signature = 'app:compute-player-stats {token}';

    protected $description = 'Calcule les stats d\'un joueur depuis des logs logs.tf (usage interne au panel admin).';

    public function handle(PlayerStatsService $service): int
    {
        $token = (string) $this->argument('token');
        if (preg_match('/^[a-z0-9]{16}$/', $token) !== 1) {
            $this->writeResult($token, ['status' => 'error', 'error' => 'Token de job invalide.']);

            return self::FAILURE;
        }

        $requestFile = hlfr_data_path('player_stats_job_'.$token.'.request.json');
        $statusFile = hlfr_data_path('player_stats_job_'.$token.'.json');

        if (! is_file($requestFile)) {
            $this->writeResult($token, ['status' => 'error', 'error' => 'Requête introuvable.']);

            return self::FAILURE;
        }

        $request = json_decode((string) file_get_contents($requestFile), true);
        if (! is_array($request)) {
            $this->writeResult($token, ['status' => 'error', 'error' => 'Requête corrompue.']);

            return self::FAILURE;
        }

        $steamId3 = $service->resolvePlayer((string) ($request['steam'] ?? ''));
        if ($steamId3 === null) {
            $this->writeResult($token, ['status' => 'error', 'error' => 'SteamID invalide.']);

            return self::FAILURE;
        }

        $logIds = [];
        foreach (($request['logs'] ?? []) as $raw) {
            $id = $service->parseLogId((string) $raw);
            if ($id !== null) {
                $logIds[] = $id;
            }
        }
        $logIds = array_values(array_unique($logIds));
        if ($logIds === []) {
            $this->writeResult($token, ['status' => 'error', 'error' => 'Aucun log logs.tf valide.']);

            return self::FAILURE;
        }

        $selected = array_values(array_intersect($service->stats(), (array) ($request['stats'] ?? [])));
        if ($selected === []) {
            $selected = $service->stats();
        }

        $this->writeResult($token, [
            'status' => 'running',
            'logs_total' => count($logIds),
            'logs_done' => 0,
            'message' => 'Démarrage de la récupération…',
            'logs_detail' => array_map(static fn (int $id): array => ['log_id' => $id, 'log_status' => 'pending'], $logIds),
        ]);

        $logsDetail = array_map(static fn (int $id): array => ['log_id' => $id, 'log_status' => 'pending'], $logIds);

        $result = $service->compute($steamId3, $logIds, $selected, function (int $done, int $total, string $message, ?array $logInfo = null) use ($token, &$logsDetail): void {
            if (is_array($logInfo)) {
                foreach ($logsDetail as &$entry) {
                    if ($entry['log_id'] === $logInfo['log_id']) {
                        $entry['log_status'] = $logInfo['log_status'];

                        break;
                    }
                }
                unset($entry);
            }

            $this->writeResult($token, [
                'status' => 'running',
                'logs_total' => $total,
                'logs_done' => $done,
                'message' => $message,
                'logs_detail' => $logsDetail,
            ]);
        });

        if ($result['logs_usable'] === 0) {
            $this->writeResult($token, [
                'status' => 'error',
                'error' => 'Aucun log exploitable pour ce joueur (vérifiez les IDs logs.tf et le SteamID saisi).',
                'result' => $result,
                'logs_detail' => $this->finalLogsDetail($result),
            ]);

            return self::FAILURE;
        }

        $this->writeResult($token, [
            'status' => 'done',
            'logs_total' => $result['logs_total'],
            'logs_done' => $result['logs_total'],
            'message' => 'Calcul terminé.',
            'result' => $result,
            'logs_detail' => $this->finalLogsDetail($result),
        ]);

        return self::SUCCESS;
    }

    /**
     * Reconstitue le détail marginal d'un log à partir du résultat final.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array{log_id: int, log_status: string}>
     */
    private function finalLogsDetail(array $result): array
    {
        $detail = [];
        foreach (($result['logs'] ?? []) as $log) {
            $status = $log['player_present'] ?? false ? 'found' : ($log['found'] ?? false ? 'absent' : 'error');
            $detail[] = ['log_id' => (int) ($log['log_id'] ?? 0), 'log_status' => $status];
        }

        return $detail;
    }

    /**
     * Écrit l'état du job (statut + progression + résultat) dans son fichier JSON.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeResult(string $token, array $data): void
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
}
