<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TeamStatsService;
use Illuminate\Console\Command;

/**
 * Calcul des stats d'une équipe (onglet « Équipe » de /admin/stats-joueur).
 *
 * Lancé en arrière-plan comme app:compute-player-stats : il lit la requête
 * dans un fichier JSON (roster + sélection de logs et de compétition par mode)
 * puis écrit la progression et le résultat dans un autre fichier JSON sous
 * storage/app/hlfr/, interrogé par le polling du navigateur.
 *
 * Un seul fetch par log : le détail est parsé une fois puis appliqué à tous
 * les membres du roster présents, pour éviter d'exploser les appels logs.tf.
 */
final class ComputeTeamStatsCommand extends Command
{
    protected $signature = 'app:compute-team-stats {token}';

    protected $description = 'Calcule les stats d\'une équipe à partir de son roster ETF2L et de logs logs.tf (usage interne au panel admin).';

    public function handle(TeamStatsService $service): int
    {
        $token = (string) $this->argument('token');
        if (preg_match('/^[a-z0-9]{16}$/', $token) !== 1) {
            $this->writeResult($token, ['status' => 'error', 'error' => 'Token de job invalide.']);

            return self::FAILURE;
        }

        $requestFile = hlfr_data_path('team_stats_job_'.$token.'.request.json');

        if (! is_file($requestFile)) {
            $this->writeResult($token, ['status' => 'error', 'error' => 'Requête introuvable.']);

            return self::FAILURE;
        }

        $request = json_decode((string) file_get_contents($requestFile), true);
        if (! is_array($request)) {
            $this->writeResult($token, ['status' => 'error', 'error' => 'Requête corrompue.']);

            return self::FAILURE;
        }

        $team = $request['team'] ?? null;
        $players = $request['players'] ?? [];
        if (! is_array($team) || ! is_array($players)) {
            $this->writeResult($token, ['status' => 'error', 'error' => 'Requête invalide (équipe manquante).']);

            return self::FAILURE;
        }

        $modes = [];
        $totalLogs = 0;
        foreach (['9v9', '6s'] as $mode) {
            $cfg = is_array($request['modes'][$mode] ?? null) ? $request['modes'][$mode] : null;
            if ($cfg === null) {
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

            $modes[$mode] = [
                'competition' => $competition,
                'logs' => $logs,
                'stats' => array_values((array) ($cfg['stats'] ?? [])),
            ];
            $totalLogs += count($logs);
        }

        if ($modes === []) {
            $this->writeResult($token, ['status' => 'error', 'error' => 'Sélectionnez au moins un log ou une compétition.']);

            return self::FAILURE;
        }

        $result = ['team' => $team, 'modes' => []];

        if ($totalLogs > 0) {
            $logsDetail = $this->collectDetail($modes, 'pending');

            $this->writeResult($token, [
                'status' => 'running',
                'logs_total' => $totalLogs,
                'logs_done' => 0,
                'message' => 'Démarrage de la récupération…',
                'logs_detail' => $logsDetail,
            ]);

            $globalDone = 0;

            foreach ($modes as $mode => $cfg) {
                $aggregated = $service->aggregateRoster(
                    $players,
                    $cfg['logs'],
                    null,
                    function (int $done, int $total, string $message, ?array $logInfo = null) use (&$logsDetail, &$globalDone, $totalLogs, $token): void {
                        if (is_array($logInfo)) {
                            $logsDetail[$logInfo['log_id']] = $logInfo['log_status'];
                            if ($logInfo['log_status'] !== 'fetching') {
                                $globalDone++;
                            }
                        }

                        $this->writeResult($token, [
                            'status' => 'running',
                            'logs_total' => $totalLogs,
                            'logs_done' => $globalDone,
                            'message' => $message,
                            'logs_detail' => $this->listDetail($logsDetail),
                        ]);
                    }
                );

                $result['modes'][$mode] = [
                    'competition' => $cfg['competition'],
                    'stats' => $cfg['stats'],
                    'players' => $aggregated['players'],
                    'logs' => $aggregated['logs'],
                ];
            }
        } else {
            // Aucun log : seuls les winrates officiels sont renvoyés.
            foreach ($modes as $mode => $cfg) {
                $result['modes'][$mode] = ['competition' => $cfg['competition'], 'stats' => $cfg['stats'], 'players' => [], 'logs' => []];
            }
        }

        $this->finalize($token, $result);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $modes
     * @return array<int, array{log_id: int, log_status: string}>
     */
    private function collectDetail(array $modes, string $status): array
    {
        $detail = [];
        foreach ($modes as $cfg) {
            foreach ($cfg['logs'] as $logId) {
                $detail[$logId] = $status;
            }
        }

        return $this->listDetail($detail);
    }

    /**
     * @param  array<int, string>  $byId
     * @return array<int, array{log_id: int, log_status: string}>
     */
    private function listDetail(array $byId): array
    {
        $out = [];
        foreach ($byId as $logId => $logStatus) {
            $out[] = ['log_id' => (int) $logId, 'log_status' => $logStatus];
        }

        return $out;
    }

    /**
     * Assemble les totaux d'équipe puis écrit le statut final « done ».
     *
     * @param  array<string, mixed>  $result
     */
    private function finalize(string $token, array $result): void
    {
        foreach (($result['modes'] ?? []) as $mode => &$modeData) {
            $this->attachTotals($modeData);
        }
        unset($modeData);

        $this->writeResult($token, [
            'status' => 'done',
            'message' => 'Calcul terminé.',
            'result' => $result,
            'logs_detail' => $this->resultDetail($result),
        ]);
    }

    /**
     * Ajoute les totaux d'équipe dérivés des lignes joueurs d'un mode.
     *
     * @param  array<string, mixed>  $modeData  (par référence)
     */
    private function attachTotals(array &$modeData): void
    {
        $players = $modeData['players'] ?? [];
        $totals = ['matches' => 0, 'kills' => 0, 'deaths' => 0, 'dmg' => 0, 'heal' => 0, 'kd' => 0.0];

        foreach ($players as $p) {
            $totals['matches'] += (int) ($p['matches'] ?? 0);
            $totals['kills'] += (int) ($p['kills'] ?? 0);
            $totals['deaths'] += (int) ($p['deaths'] ?? 0);
            $totals['dmg'] += (int) ($p['dmg'] ?? 0);
            $totals['heal'] += (int) ($p['heal'] ?? 0);
        }

        $totals['kd'] = $totals['deaths'] > 0 ? round($totals['kills'] / $totals['deaths'], 2) : ($totals['kills'] > 0 ? (float) $totals['kills'] : 0.0);

        $modeData['totals'] = $totals;
    }

    /**
     * Reconstitue le détail par log depuis le résultat final.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array{log_id: int, log_status: string}>
     */
    private function resultDetail(array $result): array
    {
        $detail = [];
        foreach (($result['modes'] ?? []) as $modeData) {
            foreach (($modeData['logs'] ?? []) as $log) {
                $logId = (int) ($log['log_id'] ?? 0);
                if ($logId <= 0) {
                    continue;
                }

                $status = ($log['present'] ?? 0) > 0 ? 'found' : (($log['found'] ?? false) ? 'absent' : 'error');
                $detail[$logId] = ['log_id' => $logId, 'log_status' => $status];
            }
        }

        $detail = array_values($detail);
        usort($detail, static fn (array $a, array $b): int => $a['log_id'] <=> $b['log_id']);

        return $detail;
    }

    /**
     * Écrit l'état du job (statut + progression + résultat) dans son fichier JSON.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeResult(string $token, array $data): void
    {
        $file = hlfr_data_path('team_stats_job_'.$token.'.json');
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
