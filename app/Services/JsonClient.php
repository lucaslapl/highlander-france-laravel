<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Client cURL minimaliste pour récupérer du JSON.
 */
final class JsonClient
{
    /**
     * Métadonnées d'un appel HTTP : payload décodé, code HTTP, erreur cURL
     * et en-têtes utiles au diagnostic/rate-limit (Retry-After, x-ratelimit-*).
     *
     * @return array{data: array|null, http_code: int, curl_error: string, headers: array<string, string>}
     */
    public static function getWithMeta(string $url, int $timeout = 10, string $userAgent = 'Mozilla/5.0', array $headers = []): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => config('hlfr.curl_verify_ssl'),
            CURLOPT_USERAGENT => $userAgent,
        ];

        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        // Collecte des en-têtes de réponse (noms normalisés en minuscules).
        $responseHeaders = [];
        $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$responseHeaders): int {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }

            return strlen($line);
        };

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            error_log('Erreur cURL ('.$url.') : '.$error);

            return ['data' => null, 'http_code' => 0, 'curl_error' => $error, 'headers' => []];
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode((string) $response, true);

        return [
            'data' => is_array($data) ? $data : null,
            'http_code' => $httpCode,
            'curl_error' => '',
            'headers' => $responseHeaders,
        ];
    }

    /**
     * POST form-urlencodé (ex. OAuth Twitch), mêmes métadonnées que getWithMeta.
     *
     * @param  array<string, string>  $fields
     * @return array{data: array|null, http_code: int, curl_error: string, headers: array<string, string>}
     */
    public static function postForm(string $url, array $fields, int $timeout = 10, string $userAgent = 'Mozilla/5.0', array $headers = []): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => config('hlfr.curl_verify_ssl'),
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ];

        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        // Collecte des en-têtes de réponse (noms normalisés en minuscules).
        $responseHeaders = [];
        $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$responseHeaders): int {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }

            return strlen($line);
        };

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            error_log('Erreur cURL ('.$url.') : '.$error);

            return ['data' => null, 'http_code' => 0, 'curl_error' => $error, 'headers' => []];
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode((string) $response, true);

        return [
            'data' => is_array($data) ? $data : null,
            'http_code' => $httpCode,
            'curl_error' => '',
            'headers' => $responseHeaders,
        ];
    }

    /**
     * @return array|null Tableau décodé, ou null si l'appel échoue.
     */
    public static function get(string $url, int $timeout = 10, string $userAgent = 'Mozilla/5.0', array $headers = []): ?array
    {
        return self::getWithMeta($url, $timeout, $userAgent, $headers)['data'];
    }

    /**
     * Récupère le corps brut d'une ressource (XML RSS, HTML…).
     *
     * @return array{body: string|null, http_code: int, curl_error: string}
     */
    public static function getRaw(string $url, int $timeout = 15, string $userAgent = 'Mozilla/5.0', array $headers = []): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => config('hlfr.curl_verify_ssl'),
            CURLOPT_USERAGENT => $userAgent,
        ];

        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            error_log('Erreur cURL ('.$url.') : '.$error);

            return ['body' => null, 'http_code' => 0, 'curl_error' => $error];
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['body' => is_string($response) ? $response : null, 'http_code' => $httpCode, 'curl_error' => ''];
    }
}
