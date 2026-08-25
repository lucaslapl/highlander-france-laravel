<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AdminLogger;
use App\Services\Crons\GenerateJsonService;
use App\Services\Crons\SyncSteamService;
use App\Services\Crons\UpdateIndexStatsService;
use App\Services\Crons\UpdateStatsService;
use App\Services\LiveMatches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints publics appelés par les serveurs de jeu et le bot Discord,
 * authentifiés par token partagé (sans session utilisateur).
 */
final class ServerHookController extends Controller
{
    private const LOCK_FILE = 'webhook_match.lock';

    /**
     * POST /api/server/live-status
     * Body (JSON) : { token, server, map, status: "live"|"ended", scores,
     *                players[], started_at, updated_at, stv }
     *
     * Léger (aucune pipeline stats) : écrit simplement l'état live dans le
     * cache des matchs en cours, consommé par /api/live-matches.
     */
    public function liveStatus(Request $request): JsonResponse
    {
        $body = $this->jsonBody($request);
        $server = (string) ($body['server'] ?? '');
        $status = (string) ($body['status'] ?? '');
        $who = $server !== '' ? $server : 'inconnu';

        if (! $this->authenticate($body)) {
            AdminLogger::log('webhook_live_status', null, 'FAILED (token invalide - ' . $who . ')');

            return response()->json(['success' => false, 'message' => 'Non autorisé.'], 403);
        }

        if (! $this->ipAllowed()) {
            AdminLogger::log('webhook_live_status', null, 'FAILED (IP non autorisée - ' . $who . ')');

            return response()->json(['success' => false, 'message' => 'IP non autorisée.'], 403);
        }

        if ($server === '' || ! in_array($status, ['live', 'ended'], true)) {
            return response()->json(['success' => false, 'message' => 'Paramètres invalides.'], 400);
        }

        $accepted = LiveMatches::apply($server, $status, $body);

        if (! $accepted) {
            AdminLogger::log('webhook_live_status', null, 'IGNORED (statut obsolète ou sans joueur - ' . $who . ')');
        }

        return response()->json([
            'success' => $accepted,
            'message' => $accepted ? 'Statut mis à jour.' : 'Statut obsolète ou sans joueur ignoré.',
        ]);
    }

    /**
     * POST /api/discord/member-count
     * Body (JSON) : { token, member_count, guild_id? }
     *
     * Appelé par le bot Discord à chaque arrivée/départ de membre et en sync
     * périodique. Écrit le dernier compteur connu dans le cache consommé par
     * /api/index-stats.
     */
    public function discordMemberCount(Request $request): JsonResponse
    {
        $body = $this->jsonBody($request);

        if (! $this->discordAuthenticate($body)) {
            AdminLogger::log('webhook_discord_member_count', null, 'FAILED (token invalide)');

            return response()->json(['success' => false, 'message' => 'Non autorisé.'], 403);
        }

        $count = $body['member_count'] ?? null;

        if (! is_numeric($count)) {
            return response()->json(['success' => false, 'message' => 'member_count invalide.'], 400);
        }

        $count = (int) $count;

        if ($count <= 0 || $count > 10000000) {
            return response()->json(['success' => false, 'message' => 'member_count hors limites.'], 400);
        }

        // Vérification optionnelle du serveur concerné (DISCORD_GUILD_ID).
        $expectedGuild = (string) config('hlfr.discord_guild_id', '');
        if ($expectedGuild !== '') {
            $guildId = (string) ($body['guild_id'] ?? '');

            if ($guildId !== '' && ! hash_equals($expectedGuild, $guildId)) {
                AdminLogger::log('webhook_discord_member_count', null, 'FAILED (guild_id inattendu - ' . $guildId . ')');

                return response()->json(['success' => false, 'message' => 'Guild non autorisée.'], 403);
            }
        }

        $cache = [
            'members' => $count,
            'updated_at' => time(),
        ];

        if (file_put_contents(hlfr_data_path('cache_discord_stats.json'), json_encode($cache), LOCK_EX) === false) {
            AdminLogger::log('webhook_discord_member_count', null, 'FAILED (écriture cache impossible)');

            return response()->json(['success' => false, 'message' => 'Écriture du cache impossible.'], 500);
        }

        AdminLogger::log('webhook_discord_member_count', null, 'SUCCESS (' . $count . ' membres)');

        return response()->json(['success' => true, 'message' => 'Compteur mis à jour.', 'members' => $count]);
    }

    /**
     * POST /api/server/match-ended
     * Body (JSON) : { token, server, map }
     */
    public function matchEnded(Request $request): JsonResponse
    {
        $body = $this->jsonBody($request);
        $server = (string) ($body['server'] ?? 'inconnu');
        $map = (string) ($body['map'] ?? '');
        $who = $server . ($map !== '' ? ' - ' . $map : '');

        if (! $this->authenticate($body)) {
            AdminLogger::log('webhook_match_ended', null, 'FAILED (token invalide - ' . $who . ')');

            return response()->json(['success' => false, 'message' => 'Non autorisé.'], 403);
        }

        if (! $this->ipAllowed()) {
            AdminLogger::log('webhook_match_ended', null, 'FAILED (IP non autorisée - ' . $who . ')');

            return response()->json(['success' => false, 'message' => 'IP non autorisée.'], 403);
        }

        // Anti-concurrence : si une mise à jour est déjà en cours, on répond 202
        // (le plugin SourceMod ne considère pas ça comme un échec).
        $lock = fopen(hlfr_data_path(self::LOCK_FILE), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return response()->json(['success' => true, 'message' => 'Mise à jour déjà en cours.'], 202);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $logToken = AdminLogger::log('webhook_match_ended');

        try {
            $updateStats = (new UpdateStatsService())->run();
            $syncSteam = (new SyncSteamService())->run();
            $generateJson = (new GenerateJsonService())->run();
            $indexStats = (new UpdateIndexStatsService())->run();

            AdminLogger::log('webhook_match_ended', $logToken, 'SUCCESS (via ' . $who . ')');

            return response()->json([
                'success' => true,
                'message' => 'Mise à jour déclenchée par webhook (' . $server . ').',
                'processed_logs' => $this->extractProcessedLogs($updateStats),
                'details' => [$updateStats, $syncSteam, $generateJson, $indexStats],
            ]);
        } catch (\Throwable $e) {
            error_log('Webhook match ended : ' . $e->getMessage());
            AdminLogger::log('webhook_match_ended', $logToken, 'FAILED (' . $e->getMessage() . ')');

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Valide le token partagé (comparaison à temps constant).
     *
     * @param array<string, mixed> $body
     */
    private function authenticate(array $body): bool
    {
        $expected = (string) config('hlfr.server_webhook_token', '');

        if ($expected === '') {
            return false;
        }

        $token = (string) ($body['token'] ?? '');

        return hash_equals($expected, $token);
    }

    /**
     * Valide le token partagé du bot Discord (comparaison à temps constant).
     *
     * @param array<string, mixed> $body
     */
    private function discordAuthenticate(array $body): bool
    {
        $expected = (string) config('hlfr.discord_webhook_token', '');

        if ($expected === '') {
            return false;
        }

        $token = (string) ($body['token'] ?? '');

        return hash_equals($expected, $token);
    }

    /**
     * Filtrage par IP optionnel (liste séparée par des virgules).
     * Vide = aucune restriction.
     */
    private function ipAllowed(): bool
    {
        $allowed = (string) config('hlfr.server_webhook_allowed_ips', '');

        if ($allowed === '') {
            return true;
        }

        $ip = (string) request()->ip();

        foreach (array_map('trim', explode(',', $allowed)) as $entry) {
            if ($entry !== '' && $entry === $ip) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrait le nombre de nouveaux logs traités du message renvoyé par
     * UpdateStatsService, pour l'exposer au plugin SourceMod.
     * Renvoie -1 si le message ne peut pas être interprété.
     */
    private function extractProcessedLogs(string $message): int
    {
        if (preg_match('/Nouveaux logs traités\s*:\s*(\d+)/i', $message, $m) === 1) {
            return (int) $m[1];
        }

        return -1;
    }

    /**
     * Corps JSON décodé (php://input). Compatible avec le POST JSON du plugin
     * et les tests via curl.
     *
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        if ($request->isJson()) {
            $data = $request->json()->all();

            return is_array($data) ? $data : [];
        }

        // Fallback : le plugin SourceMod envoie parfois le body sans Content-Type.
        $raw = $request->getContent();
        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : [];
    }
}
