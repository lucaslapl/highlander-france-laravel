<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;
use App\Services\JsonClient;
use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

final class SyncFranceService
{
    private const SCRIPT_NAME = 'sync_france.php';

    private const LOCK_FILE = 'sync_france.lock';

    private const API_CALL_DELAY_S = 1.1;

    private const HTTP_TIMEOUT_S = 15;

    private const CACHE_TTL_TEAMS = 3600;

    private \PDO $db;

    private float $lastHttpAt = 0;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    private function cachedGet(string $url, int $ttl): array
    {
        $stmt = $this->db->prepare('SELECT payload FROM etf2l_api_cache WHERE url = ? AND fetched_at > ?');
        $stmt->execute([$url, time() - $ttl]);
        $payload = $stmt->fetchColumn();

        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $elapsed = microtime(true) - $this->lastHttpAt;
        if ($this->lastHttpAt > 0 && $elapsed < self::API_CALL_DELAY_S) {
            usleep((int) ((self::API_CALL_DELAY_S - $elapsed) * 1e6));
        }
        $this->lastHttpAt = microtime(true);

        $data = $this->fetchWithRetry($url);

        $this->db
            ->prepare('INSERT INTO etf2l_api_cache (url, payload, fetched_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)')
            ->execute([$url, json_encode($data, JSON_THROW_ON_ERROR), time()]);

        return $data;
    }

    private function fetchWithRetry(string $url, int $attempts = 3): array
    {
        $backoffs = [0, 5, 20];
        $lastHeaders = [];
        $lastError = 'raison inconnue';

        for ($i = 1; $i <= $attempts; $i++) {
            if ($i > 1) {
                $retryAfter = isset($lastHeaders['retry-after']) ? (int) $lastHeaders['retry-after'] : 0;
                sleep(min(max($retryAfter, $backoffs[min($i - 1, count($backoffs) - 1)]), 90));
            }

            $meta = JsonClient::getWithMeta($url, self::HTTP_TIMEOUT_S, 'Highlander France Bot/1.0', ['Accept: application/json']);
            $lastHeaders = $meta['headers'];

            if ($meta['curl_error'] !== '') {
                $lastError = 'erreur cURL : ' . $meta['curl_error'];
                continue;
            }

            if ($meta['data'] === null) {
                $lastError = 'HTTP ' . $meta['http_code'] . ' avec réponse non-JSON';
                continue;
            }

            $code = isset($meta['data']['status']['code']) ? (int) $meta['data']['status']['code'] : null;

            if ($code === 200) {
                return $meta['data'];
            }

            if ($code === null || in_array($code, [429, 500, 502, 503, 504], true)) {
                $lastError = 'HTTP ' . ($code ?? $meta['http_code']) . ' (réponse transitoire)';
                continue;
            }

            $msg = (string) ($meta['data']['status']['message'] ?? 'Réponse invalide/inaccessible');
            throw new \RuntimeException("L'API ETF2L a répondu négativement pour {$url} : " . $msg);
        }

        throw new \RuntimeException("Appel API ETF2L impossible après {$attempts} tentatives ({$url}) : " . $lastError);
    }

    public function run(): string
    {
        $lock = fopen(hlfr_data_path(self::LOCK_FILE), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return 'Synchronisation France ignorée : une autre exécution est déjà en cours.';
        }

        try {
            return $this->doRun();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function doRun(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        $config = config('hlfr.france_teams', []);
        $teams = [
            '6v6' => array_values(array_filter(array_map('intval', (array) ($config['6v6'] ?? [])))),
            'highlander' => array_values(array_filter(array_map('intval', (array) ($config['highlander'] ?? [])))),
        ];

        $allTeamIds = array_merge($teams['6v6'], $teams['highlander']);

        if ($allTeamIds === []) {
            $msg = 'Aucune équipe de France configurée (hlfr.france_teams).';
            AdminLogger::log(self::SCRIPT_NAME, $logToken, 'ERROR ' . $msg);
            return $msg;
        }

        $fetched = [];

        foreach ($teams as $mode => $ids) {
            foreach ($ids as $teamId) {
                try {
                    $data = $this->cachedGet('https://api-v2.etf2l.org/team/' . $teamId, self::CACHE_TTL_TEAMS);
                } catch (\Throwable $e) {
                    error_log('Roster France ' . $mode . ' équipe ' . $teamId . ' : ' . $e->getMessage());
                    continue;
                }

                if (!isset($data['status']['code']) || (int) $data['status']['code'] !== 200) {
                    continue;
                }

                $team = $data['team'] ?? null;
                if (!is_array($team)) {
                    continue;
                }

                foreach (($team['players'] ?? []) as $p) {
                    $steam64 = isset($p['steam']['id64']) ? (string) $p['steam']['id64'] : null;
                    if ($steam64 === null || $steam64 === '') {
                        continue;
                    }

                    $steam3 = SteamId::toSteamId3($steam64);

                    $fetched[] = [
                        'steamid' => $steam3,
                        'steamid64' => $steam64,
                        'etf2l_player_id' => (int) ($p['id'] ?? 0),
                        'mode' => $mode,
                        'team_id' => $teamId,
                    ];
                }
            }
        }

        $byKey = [];
        foreach ($fetched as $row) {
            $byKey[$row['steamid'] . '|' . $row['mode']] = $row;
        }
        $fetched = array_values($byKey);

        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM france_national_players');

            if ($fetched !== []) {
                $stmt = $this->db->prepare('INSERT INTO france_national_players (steamid, steamid64, etf2l_player_id, mode, team_id, fetched_at) VALUES (?, ?, ?, ?, ?, ?)');
                $now = time();
                foreach ($fetched as $row) {
                    $stmt->execute([$row['steamid'], $row['steamid64'], $row['etf2l_player_id'], $row['mode'], $row['team_id'], $now]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $count6v6 = count(array_filter($fetched, static fn ($r) => $r['mode'] === '6v6'));
        $countHl = count(array_filter($fetched, static fn ($r) => $r['mode'] === 'highlander'));

        $statusMsg = 'SUCCESS (' . count($fetched) . ' joueurs : ' . $count6v6 . ' 6v6, ' . $countHl . ' Highlander)';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        return 'Badges France synchronisés : ' . count($fetched) . ' joueur(s) (' . $count6v6 . ' 6v6, ' . $countHl . ' Highlander).';
    }
}
