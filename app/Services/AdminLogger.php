<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Journalisation et audit d'exécution des scripts CRON, webhooks et tests
 * API (table admin_logs).
 *
 * L'API historique est conservée : on appelle log($script) au début d'un
 * traitement (ligne STARTED), puis log($script, $token, 'SUCCESS (...)') à
 * la fin pour mettre à jour le statut de la même entrée.
 */
final class AdminLogger
{
    /**
     * Enregistre le début (STARTED) ou la fin (SUCCESS / raison d'échec) d'un script.
     *
     * @return string L'identifiant du log généré (ou mis à jour).
     */
    public static function log(string $scriptName, ?string $updateId = null, string $status = 'STARTED'): string
    {
        if ($updateId !== null && ctype_digit($updateId)) {
            self::finish((int) $updateId, $status);

            return $updateId;
        }

        return (string) self::start($scriptName, $status);
    }

    /**
     * Dernière exécution terminée (SUCCESS/FAILED/IGNORED) de chaque script.
     *
     * @return array<string, array{status: string, message: string, date: string, ts: int}>
     */
    public static function lastRuns(): array
    {
        $ids = DB::table('admin_logs')
            ->selectRaw('MAX(id) as id')
            ->whereIn('status', ['success', 'failed', 'ignored'])
            ->groupBy('script')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $last = [];
        foreach (DB::table('admin_logs')->whereIn('id', $ids)->get() as $row) {
            if ($row->finished_at === null) {
                continue;
            }

            $finished = Carbon::parse($row->finished_at)->setTimezone('Europe/Paris');
            $message = ucfirst($row->status) . ($row->message !== null && $row->message !== '' ? " ({$row->message})" : '');

            $last[$row->script] = [
                'status' => $row->status === 'failed' ? 'failed' : 'success',
                'message' => $message,
                'date' => $finished->format('Y-m-d H:i:s'),
                'ts' => $finished->getTimestamp(),
            ];
        }

        return $last;
    }

    private static function start(string $script, string $rawStatus): int
    {
        ['context' => $context, 'steamid' => $steamid, 'name' => $name, 'ip' => $ip] = self::actor();

        // Log à entrée unique (ex: webhook) : déjà terminé dès l'écriture.
        if (str_starts_with(strtoupper(trim($rawStatus)), 'STARTED')) {
            $status = 'started';
            $message = null;
            $startedAt = Carbon::now();
            $finishedAt = null;
        } else {
            [$base, $message] = self::parseStatus($rawStatus);
            $status = strtolower($base);
            $startedAt = $finishedAt = Carbon::now();
        }

        return (int) DB::table('admin_logs')->insertGetId([
            'script' => $script,
            'status' => $status,
            'message' => $message,
            'context' => $context,
            'user_steamid' => $steamid,
            'user_name' => $name,
            'ip' => $ip,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
    }

    /**
     * Met à jour une entrée STARTED avec son statut final.
     * Format historique accepté : "SUCCESS (détail)" / "FAILED (raison)" / "IGNORED (...)".
     */
    private static function finish(int $id, string $rawStatus): void
    {
        [$base, $message] = self::parseStatus($rawStatus);
        $finishedAt = Carbon::now();

        // Filet de sécurité : l'entrée STARTED a disparu (purge concurrente...)
        // → on enregistre quand même le statut final dans une nouvelle ligne.
        $existing = DB::table('admin_logs')->where('id', $id)->first();

        if ($existing === null) {
            ['context' => $context, 'steamid' => $steamid, 'name' => $name, 'ip' => $ip] = self::actor();

            DB::table('admin_logs')->insert([
                'script' => 'inconnu',
                'status' => strtolower($base),
                'message' => $message,
                'context' => $context,
                'user_steamid' => $steamid,
                'user_name' => $name,
                'ip' => $ip,
                'started_at' => $finishedAt,
                'finished_at' => $finishedAt,
            ]);

            return;
        }

        // Déjà finalisée (double appel de fin) : on ne touche à rien.
        if ($existing->status !== 'started') {
            return;
        }

        DB::table('admin_logs')
            ->where('id', $id)
            ->update([
                'status' => strtolower($base),
                'message' => $message,
                'finished_at' => $finishedAt,
            ]);
    }

    /**
     * Découpe un libellé brut ("SUCCESS (3 logs traités)") en base + message.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function parseStatus(string $rawStatus): array
    {
        $trimmed = trim($rawStatus);

        if (preg_match('/^(SUCCESS|FAILED|IGNORED)\b\s*\((.*)\)\s*$/is', $trimmed, $m)) {
            return [strtoupper($m[1]), trim($m[2]) !== '' ? trim($m[2]) : null];
        }

        // Libellé sans parenthèses ou non standard : conservé tel quel.
        return [str_starts_with(strtoupper($trimmed), 'FAILED') ? 'FAILED' : 'SUCCESS', $trimmed];
    }

    /**
     * Origine de l'appel : tâche planifiée, webhook serveur/bot Discord ou
     * utilisateur web (avec steamid + pseudo depuis la session Laravel).
     *
     * @return array{context: string, steamid: ?string, name: string, ip: ?string}
     */
    private static function actor(): array
    {
        if (app()->runningInConsole()) {
            return ['context' => 'cli', 'steamid' => null, 'name' => 'SERVER (CLI / CRON)', 'ip' => null];
        }

        $request = request();

        if ($request !== null && ($request->is('api/server/*') || $request->is('api/discord/*'))) {
            return ['context' => 'webhook', 'steamid' => null, 'name' => 'SERVER WEBHOOK', 'ip' => self::clientIp($request)];
        }

        if ($request === null) {
            return ['context' => 'web', 'steamid' => null, 'name' => 'Visiteur', 'ip' => null];
        }

        $steamid64 = Auth::steamId64();

        if ($steamid64 === null) {
            return ['context' => 'web', 'steamid' => null, 'name' => 'Visiteur', 'ip' => self::clientIp($request)];
        }

        $pseudo = 'Inconnu';
        try {
            $player = DB::table('players_info')
                ->where('steamid', SteamId::toSteamId3($steamid64))
                ->first();
            if ($player !== null) {
                $pseudo = $player->display_name ?: ($player->name ?: 'Inconnu');
            }
        } catch (\Exception) {
            $pseudo = 'Erreur BDD';
        }

        return ['context' => 'web', 'steamid' => $steamid64, 'name' => $pseudo, 'ip' => self::clientIp($request)];
    }

    private static function clientIp(\Illuminate\Http\Request $request): string
    {
        if ($request->headers->has('X-Forwarded-For')) {
            $forwarded = trim(explode(',', (string) $request->header('X-Forwarded-For'))[0]);
            if ($forwarded !== '') {
                return $forwarded;
            }
        }

        return (string) ($request->ip() ?? 'IP Inconnue');
    }
}
