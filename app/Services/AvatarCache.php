<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class AvatarCache
{
    private const DIR = 'storage/avatars';

    private const TTL = 86400 * 30;

    /** @var array<string, string|null> */
    private static array $memory = [];

    public static function urlFor(string $steamid64): string
    {
        if (! preg_match('/^\d{17}$/', $steamid64)) {
            return '/img/avatar/'.$steamid64;
        }

        if (array_key_exists($steamid64, self::$memory)) {
            $cached = self::$memory[$steamid64];

            return $cached ?? '/img/avatar/'.$steamid64;
        }

        $file = self::existingFile($steamid64);
        $url = $file !== null ? '/'.self::DIR.'/'.$file : '/img/avatar/'.$steamid64;
        self::$memory[$steamid64] = $file !== null ? $url : null;

        return $url;
    }

    public static function warm(string $steamid64, string $source): ?string
    {
        if (! preg_match('/^\d{17}$/', $steamid64)) {
            return null;
        }

        if (self::existingFile($steamid64) !== null) {
            return self::urlFor($steamid64);
        }

        if ($source === '' || preg_match('#^https?://#', $source) !== 1) {
            return null;
        }

        $lock = Cache::lock('avatar-warm-'.$steamid64, 10);

        if (! $lock->get()) {
            return null;
        }

        try {
            return self::fetchAndStore($steamid64, $source);
        } finally {
            $lock->release();
        }
    }

    public static function fetchAndStore(string $steamid64, string $source): ?string
    {
        $ext = strtolower((string) pathinfo((string) parse_url($source, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            $ext = 'jpg';
        }
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $dir = public_path(self::DIR);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $ch = curl_init($source);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => (bool) config('hlfr.curl_verify_ssl', true),
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Referer: https://steamcommunity.com/'],
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false || $data === '' || $code < 200 || $code >= 300) {
            return null;
        }

        foreach (['jpg', 'png', 'gif'] as $old) {
            if ($old !== $ext) {
                @unlink($dir.'/'.$steamid64.'.'.$old);
            }
        }

        $tmp = $dir.'/'.$steamid64.'.'.$ext.'.tmp';
        if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
            return null;
        }
        @rename($tmp, $dir.'/'.$steamid64.'.'.$ext);

        self::$memory[$steamid64] = '/'.self::DIR.'/'.$steamid64.'.'.$ext;

        return self::$memory[$steamid64];
    }

    public static function forget(string $steamid64): void
    {
        unset(self::$memory[$steamid64]);
    }

    private static function existingFile(string $steamid64): ?string
    {
        $dir = public_path(self::DIR);

        foreach (['jpg', 'png', 'gif'] as $ext) {
            if (is_file($dir.'/'.$steamid64.'.'.$ext)) {
                return $steamid64.'.'.$ext;
            }
        }

        return null;
    }
}
