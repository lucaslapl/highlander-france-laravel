<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Journalisation et audit d'exécution des scripts CRON (cron_debug.log).
 */
final class AdminLogger
{
    private const LOG_FILE = 'cron_debug.log';

    /**
     * Enregistre le début (STARTED) ou la fin (SUCCESS / raison d'échec) d'un script.
     *
     * @return string Le token unique du log généré (ou mis à jour).
     */
    public static function log(string $scriptName, ?string $updateId = null, string $status = 'STARTED'): string
    {
        date_default_timezone_set('Europe/Paris');

        // Mode "mise à jour" : on remplace la ligne STARTED par le statut final.
        if ($updateId !== null) {
            $file = hlfr_data_path(self::LOG_FILE);
            if (is_file($file)) {
                $content = (string) file_get_contents($file);
                $search = "[TOKEN:{$updateId}] [STATUS:STARTED]";
                $replace = "[TOKEN:{$updateId}] [STATUS:{$status}]";

                if (strpos($content, $search) !== false) {
                    file_put_contents($file, str_replace($search, $replace, $content), LOCK_EX);

                    return $updateId;
                }
            }
        }

        $token = uniqid('req_', true);
        $date = date('Y-m-d H:i:s');
        $line = "[{$date}] [TOKEN:{$token}] [STATUS:{$status}] [SCRIPT: {$scriptName}] [BY: " . self::who() . ']' . PHP_EOL;

        file_put_contents(hlfr_data_path(self::LOG_FILE), $line, FILE_APPEND | LOCK_EX);

        return $token;
    }

    /**
     * Dernière exécution (SUCCESS/FAILED) de chaque script dans cron_debug.log.
     *
     * @return array<string, array{status: string, message: string, date: string, ts: int}>
     */
    public static function lastRuns(): array
    {
        $file = hlfr_data_path(self::LOG_FILE);
        if (! is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $lines = array_slice($lines, -2000);

        $last = [];
        foreach ($lines as $line) {
            if (! preg_match('/\[SCRIPT:\s*([a-z0-9_\.]+)\]/i', $line, $m)) {
                continue;
            }
            $script = $m[1];

            if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*\[STATUS:(.*?)\]\s*\[SCRIPT:/i', $line, $m2)) {
                continue;
            }
            $status = $m2[2];
            if (strpos($status, 'STARTED') !== false) {
                continue;
            }

            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $m2[1], new \DateTimeZone('Europe/Paris'));

            $last[$script] = [
                'status' => str_starts_with($status, 'FAILED') ? 'failed' : 'success',
                'message' => $status,
                'date' => $m2[1],
                'ts' => $dt ? $dt->getTimestamp() : 0,
            ];
        }

        return $last;
    }

    private static function who(): string
    {
        if (app()->runningInConsole()) {
            return 'SERVER (CLI / CRON)';
        }

        $steamid64 = $_SESSION['steamid'] ?? 'Pas de SteamID';

        $ip = request()->ip() ?? 'IP Inconnue';
        if (request()->headers->has('X-Forwarded-For')) {
            $ip = trim(explode(',', (string) request()->header('X-Forwarded-For'))[0]);
        }

        $pseudo = 'Inconnu';
        if (isset($_SESSION['steamid'])) {
            try {
                $player = DB::table('players_info')
                    ->where('steamid', SteamId::toSteamId3((string) $steamid64))
                    ->first();
                if ($player) {
                    $pseudo = $player->display_name;
                }
            } catch (\Exception) {
                $pseudo = 'Erreur BDD';
            }
        }

        return "Web User: {$pseudo} ({$steamid64}) - IP: {$ip}";
    }
}
