<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminLogger;
use App\Services\Auth;
use App\Services\MatchFormat;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pages du panel d'administration : exécution manuelle des CRON
 * et consultation des journaux.
 */
final class AdminCronController extends Controller
{
    /**
     * GET|POST /admin/match-logs
     */
    public function matchLogs(): View
    {
        Auth::requireAdmin();

        // 1. Index logs.tf : fusion des deux titres, dédup, exclusion blacklist.
        $logs = [];
        foreach (['Highlander%20France', 'highlanderfrance.tf'] as $title) {
            $data = $this->fetchJson('https://logs.tf/api/v1/log?title=' . $title . '&limit=200');
            foreach (($data['logs'] ?? []) as $log) {
                $logs[(int) ($log['id'] ?? 0)] = $log;
            }
        }

        $blacklist = (new \App\Models\MatchLogRepository())->blacklistedIds();
        $merged = [];
        foreach ($logs as $id => $log) {
            if ($id !== 0 && ! in_array($id, $blacklist, true)) {
                $merged[$id] = $log;
            }
        }
        uasort($merged, static fn (array $a, array $b): int => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));

        // 2. Durées : cache en base puis fetch des logs manquants.
        $repo = new AdminRepository();
        $cache = $repo->logLengths();
        $missingIds = [];
        foreach ($merged as $log) {
            $logId = (int) ($log['id'] ?? 0);
            if ($logId !== 0 && ! isset($cache[$logId])) {
                $missingIds[] = $logId;
            }
        }
        if ($missingIds !== []) {
            $newLengths = $this->fetchLogLengths($missingIds);
            $repo->saveLogLengths($newLengths);
            foreach ($newLengths as $id => $length) {
                $cache[$id] = $length;
            }
        }

        $dbModes = $repo->dbModes();

        // 3. Construction des lignes HTML.
        $rows = '';
        foreach ($merged as $log) {
            $logId = (int) ($log['id'] ?? 0);
            $title = (string) ($log['title'] ?? '');
            $map = (string) ($log['map'] ?? '');
            $date = $log['date'] ?? null;
            $players = (int) ($log['players'] ?? 0);
            $length = $cache[$logId] ?? null;

            $durationStr = $this->formatDuration($length);
            $durationCls = ($length !== null && $length < 600) ? ' cell-warning' : '';

            $modeForCheck = $dbModes[$logId] ?? null;
            if ($modeForCheck === null) {
                if (stripos($title, '[6s]') !== false) {
                    $modeForCheck = '6s';
                } elseif (stripos($title, '[9s]') !== false) {
                    $modeForCheck = '9v9';
                }
            }

            $playersCls = '';
            if ($modeForCheck === '6s' && $players < 12) {
                $playersCls = ' cell-warning';
            } elseif ($modeForCheck === '9v9' && $players < 18) {
                $playersCls = ' cell-warning';
            }

            $dbMode = $dbModes[$logId] ?? null;
            $modeBadge = $dbMode
                ? '<span class="badge mode-badge">' . e((string) $dbMode) . '</span>'
                : '<span style="color:#555;">—</span>';

            $blacklistBtn = '<button type="button" class="btn-icon btn-blacklist" data-log-id="' . $logId . '" data-log-title="' . e($title) . '" title="Exclure ce log des statistiques"><i class="fa-solid fa-ban"></i></button>';

            if ($dbMode) {
                $targetMode = ($dbMode === '6s') ? '9v9' : '6s';
                $modeBtn = '<button type="button" class="btn-icon btn-mode" data-log-id="' . $logId . '" data-mode="' . $targetMode . '" title="Passer ce log en mode ' . $targetMode . '"><i class="fa-solid fa-arrows-rotate"></i></button>';
            } else {
                $modeBtn = '<button type="button" class="btn-icon btn-mode" disabled title="Log non traité en base"><i class="fa-solid fa-arrows-rotate"></i></button>';
            }

            $dateStr = $date ? date('d/m/Y H:i', (int) $date) : '—';

            $rows .= '<tr>
                <td>' . $dateStr . '</td>
                <td>' . e($map) . '</td>
                <td><a href="https://logs.tf/' . $logId . '" target="_blank">' . e($title) . '</a></td>
                <td class="' . $playersCls . ' text-center">' . $players . '</td>
                <td class="' . $durationCls . ' text-center">' . $durationStr . '</td>
                <td class="text-center">' . $modeBadge . '</td>
                <td class="text-center" style="white-space: nowrap;">' . $blacklistBtn . $modeBtn . '</td>
            </tr>';
        }

        return view('admin.match_logs', [
            'title' => 'Admin - Logs des matchs joués',
            'description' => 'Liste des matchs joués avec joueurs et durée.',
            'styles' => ['/_css/admin.css'],
            'rows' => $rows,
        ]);
    }

    /**
     * GET|POST /admin/run-cron-manual — console d'exécution des tâches CRON.
     */
    public function runCronManual(Request $request): View
    {
        Auth::requireAdmin();

        $availableScripts = [
            'etf2l_matches' => \App\Services\Crons\SyncEtf2lService::class,
            'etf2l_backfill' => \App\Services\Crons\BackfillEtf2lHistoryService::class,
            'index_stats' => \App\Services\Crons\UpdateIndexStatsService::class,
            'match_stats' => \App\Services\Crons\UpdateStatsService::class,
            'sync_with_steam' => \App\Services\Crons\SyncSteamService::class,
            'generate_json' => \App\Services\Crons\GenerateJsonService::class,
            'sync_steam_avatars' => \App\Services\Crons\SyncSteamAvatarsService::class,
            'backfill_log_dates' => \App\Services\Crons\BackfillLogDatesService::class,
            'migrate_player_match_stats' => \App\Services\Crons\MigratePlayerMatchStatsService::class,
            'backfill_player_match_stats' => \App\Services\Crons\BackfillPlayerMatchStatsService::class,
            'backfill_match_teams' => \App\Services\Crons\BackfillMatchTeamsService::class,
        ];

        $output = '';
        $executed = false;
        $selectedAction = '';
        $returnStatus = 0;

        if ($request->isMethod('POST') && $request->input('trigger_cron') !== null) {
            $selectedAction = (string) $request->input('cron_action', '');

            if (array_key_exists($selectedAction, $availableScripts)) {
                $serviceClass = $availableScripts[$selectedAction];

                try {
                    // Certains crons (ETF2L, backfills) enchaînent des dizaines
                    // d'appels API : on dépasse le max_execution_time du web.
                    set_time_limit(300);
                    $service = new $serviceClass();
                    $output = $service->run();
                    $returnStatus = 0;
                } catch (\Throwable $e) {
                    $output = "[ERREUR FATALE LORS DE L'EXÉCUTION] :\n" . $e->getMessage();
                    $returnStatus = 1;
                }

                if ($output === '') {
                    $output = "Le script s'est exécuté avec succès mais n'a renvoyé aucun texte.";
                }
                $executed = true;
            } else {
                $output = 'Erreur de sécurité : Action non autorisée.';
                $returnStatus = 1;
                $executed = true;
            }
        }

        return view('admin.run_cron_manual', [
            'title' => 'Admin - Tâches Multi-CRON',
            'description' => 'Console d\'exécution des tâches CRON.',
            'styles' => ['/_css/admin.css'],
            'availableScripts' => $availableScripts,
            'output' => $output,
            'executed' => $executed,
            'selectedAction' => $selectedAction,
            'returnStatus' => $returnStatus,
        ]);
    }

    /**
     * GET|POST /admin/view-logs
     */
    public function viewLogs(Request $request): View|RedirectResponse
    {
        Auth::requireAdmin();

        $logFile = hlfr_data_path('cron_debug.log');
        $fileExists = false;
        $fileSize = '0 Octets';
        $bytes = 0;
        $logContent = '';

        if (is_file($logFile)) {
            $fileExists = true;
            $bytes = (int) filesize($logFile);

            if ($bytes >= 1048576) {
                $fileSize = number_format($bytes / 1048576, 2) . ' Mo';
            } elseif ($bytes >= 1024) {
                $fileSize = number_format($bytes / 1024, 2) . ' Ko';
            } else {
                $fileSize = $bytes . ' Octets';
            }

            $fileLines = file($logFile);
            if ($fileLines !== false) {
                $lastLines = array_slice($fileLines, -100);
                $logContent = implode('', array_reverse($lastLines));
            }

            if ($logContent === '') {
                $logContent = 'Le fichier de log existe mais il est actuellement vide.';
            }
        } else {
            $logContent = "Aucun enregistrement trouvé.\nLe fichier 'cron_debug.log' n'a pas encore été généré dans le répertoire de données.";
        }

        if ($request->isMethod('POST') && $request->input('clear_logs') !== null) {
            if ($fileExists) {
                file_put_contents($logFile, '');
            }

            return redirect('/admin/view-logs')->with('success', "Le journal d'erreurs a été réinitialisé avec succès !");
        }

        return view('admin.view_logs', [
            'title' => 'Admin - Journaux Système',
            'description' => 'Inspecteur de journaux (cron_debug.log).',
            'styles' => ['/_css/admin.css'],
            'fileSize' => $fileSize,
            'fileExists' => $fileExists,
            'bytes' => $bytes,
            'logContent' => $logContent,
        ]);
    }

    // --- Helpers privés ---

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => config('hlfr.curl_verify_ssl'),
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return [];
        }

        $decoded = json_decode((string) $response, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Durées (en secondes) des logs manquants, en parallèle (cURL multi), par lots de 10.
     *
     * @param  int[]  $logIds
     * @return array<int, int>
     */
    private function fetchLogLengths(array $logIds, int $batchSize = 10, int $sleepMicros = 300000): array
    {
        $lengths = [];
        foreach (array_chunk(array_values($logIds), $batchSize) as $batch) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($batch as $id) {
                $ch = curl_init('https://logs.tf/api/v1/log/' . $id);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_SSL_VERIFYPEER => config('hlfr.curl_verify_ssl'),
                    CURLOPT_USERAGENT      => 'Mozilla/5.0',
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$id] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh);
                }
            } while ($running > 0);

            foreach ($handles as $id => $ch) {
                $data = json_decode((string) curl_multi_getcontent($ch), true);
                if (isset($data['length'])) {
                    $lengths[$id] = (int) $data['length'];
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);

            if (count($batch) === $batchSize) {
                usleep($sleepMicros);
            }
        }

        return $lengths;
    }

    private function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $parts = MatchFormat::durationParts((int) $seconds);

        return $parts['min'] > 0 ? $parts['min'] . ' min ' . $parts['sec'] . ' s' : $parts['sec'] . ' s';
    }
}
