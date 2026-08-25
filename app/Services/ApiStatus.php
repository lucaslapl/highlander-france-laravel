<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Statut des API externes (ETF2L, logs.tf, Steam) pour le dashboard admin.
 * Vérifications en direct via cURL, mises en cache quelques secondes.
 */
final class ApiStatus
{
    private const CACHE_FILE = 'api_status_cache.json';
    private const CACHE_TTL = 60;
    private const SLOW_MS = 2000;

    /**
     * Point d'entrée : retourne les statuts (avec cache court).
     *
     * @return array<string, array<string, mixed>>
     */
    public function get(bool $forceRefresh = false): array
    {
        $cacheFile = hlfr_data_path(self::CACHE_FILE);

        if (! $forceRefresh && is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['checked_at'], $cached['checks'])
                && (time() - (int) $cached['checked_at']) < self::CACHE_TTL) {
                return $cached['checks'];
            }
        }

        $checks = $this->checks();
        $lastRuns = AdminLogger::lastRuns();

        foreach ($checks as $key => $check) {
            $checks[$key]['last_sync'] = $lastRuns[$check['script']] ?? null;
        }

        @file_put_contents($cacheFile, json_encode(['checked_at' => time(), 'checks' => $checks]), LOCK_EX);

        return $checks;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function checks(): array
    {
        $checks = [];

        // --- ETF2L ---
        $r = $this->curl('https://api-v2.etf2l.org/matches?scheduled=1');
        $data = json_decode((string) $r['body'], true);
        $valid = $r['http_code'] === 200 && is_array($data['results']['data'] ?? null);
        $checks['etf2l'] = [
            'api' => 'ETF2L',
            'icon' => 'fa-solid fa-flag-checkered',
            'status' => $this->evalStatus($r['http_code'], $valid, $r['latency_ms']),
            'http_code' => $r['http_code'],
            'latency_ms' => $r['latency_ms'],
            'message' => $this->message($valid, $r),
            'script' => 'sync_etf2l.php',
        ];

        // --- logs.tf ---
        $r = $this->curl('https://logs.tf/api/v1/log?title=Highlander%20France&limit=1');
        $data = json_decode((string) $r['body'], true);
        $valid = $r['http_code'] === 200 && is_array($data['logs'] ?? null);
        $checks['logstf'] = [
            'api' => 'LOGS.TF',
            'icon' => 'fa-solid fa-fire',
            'status' => $this->evalStatus($r['http_code'], $valid, $r['latency_ms']),
            'http_code' => $r['http_code'],
            'latency_ms' => $r['latency_ms'],
            'message' => $this->message($valid, $r),
            'script' => 'update_stats.php',
        ];

        // --- Steam ---
        $key = (string) config('hlfr.steam_api_key', '');
        if ($key === '') {
            $checks['steam'] = [
                'api' => 'Steam',
                'icon' => 'fa-brands fa-steam',
                'status' => 'error',
                'http_code' => null,
                'latency_ms' => null,
                'message' => 'Clé API manquante dans le fichier .env',
                'script' => 'app:sync-steam',
            ];
        } else {
            $r = $this->curl('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=' . $key . '&steamids=76561197960265729');
            $data = json_decode((string) $r['body'], true);
            $resp = $data['response'] ?? null;
            $valid = $r['http_code'] === 200 && is_array($resp) && empty($resp['error'] ?? null);
            $checks['steam'] = [
                'api' => 'Steam',
                'icon' => 'fa-brands fa-steam',
                'status' => $this->evalStatus($r['http_code'], $valid, $r['latency_ms']),
                'http_code' => $r['http_code'],
                'latency_ms' => $r['latency_ms'],
                'message' => $valid
                    ? 'API opérationnelle'
                    : (is_array($resp) && ! empty($resp['error']) ? 'Réponse Steam invalide (clé ?)' : $this->message($valid, $r)),
                'script' => 'sync_steam.php',
            ];
        }

        return $checks;
    }

    /**
     * @return array{body: string, http_code: int, latency_ms: int, error: string}
     */
    private function curl(string $url, int $timeout = 5): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => config('hlfr.curl_verify_ssl'),
            CURLOPT_USERAGENT      => 'Highlander France Bot/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'body' => $body === false ? '' : (string) $body,
            'http_code' => (int) ($info['http_code'] ?? 0),
            'latency_ms' => (int) round(($info['total_time'] ?? 0) * 1000),
            'error' => (string) $error,
        ];
    }

    private function evalStatus(int $httpCode, bool $valid, int $latencyMs): string
    {
        if (!$valid || $httpCode <= 0) {
            return 'down';
        }
        if ($latencyMs > self::SLOW_MS) {
            return 'slow';
        }

        return 'ok';
    }

    private function message(bool $valid, array $r): string
    {
        if ($valid) {
            return 'API opérationnelle';
        }
        if (!empty($r['error'])) {
            return 'Erreur cURL : ' . $r['error'];
        }
        if ($r['http_code'] > 0) {
            return 'Réponse invalide (HTTP ' . $r['http_code'] . ')';
        }

        return 'Connexion impossible';
    }
}
