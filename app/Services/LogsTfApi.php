<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Client de l'API logs.tf.
 *
 * Le cache JSON (hlfr_data_path/cache_hlfr_logs.json) est invalide lorsque la
 * blacklist change, pour rester cohérent avec le panel admin.
 */
final class LogsTfApi
{
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Liste des logs "Highlander France", hors blacklist, triés du plus récent au plus ancien.
     *
     * @return array<int, array<string, mixed>>
     */
    public function filteredLogs(): array
    {
        $cacheFile = hlfr_data_path('cache_hlfr_logs.json');

        // Cache frais : on sert directement sans toucher l'API logs.tf.
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            return $this->readJson($cacheFile);
        }

        $logs = array_merge(
            $this->fetchLogs('Highlander%20France'),
            $this->fetchLogs('highlanderfrance.tf'),
        );

        // API injoignable : on renvoie le cache même expiré plutôt que rien.
        if ($logs === [] && is_file($cacheFile)) {
            return $this->readJson($cacheFile);
        }

        $blacklist = (new \App\Models\MatchLogRepository)->blacklistedIds();

        $filtered = [];
        foreach ($logs as $log) {
            $logId = (int) ($log['id'] ?? 0);
            if ($logId !== 0 && ! in_array($logId, $blacklist, true)) {
                $filtered[$logId] = $log;
            }
        }

        usort($filtered, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);

        $logsList = array_values($filtered);
        @file_put_contents($cacheFile, json_encode($logsList), LOCK_EX);

        return $logsList;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLogs(string $titleQuery): array
    {
        $url = 'https://logs.tf/api/v1/log?title=' . $titleQuery;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => config('hlfr.curl_verify_ssl'),
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $response = curl_exec($ch);

        if ($response === false) {
            curl_close($ch);

            return [];
        }

        curl_close($ch);

        $data = json_decode((string) $response, true);

        return $data['logs'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJson(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }
}
