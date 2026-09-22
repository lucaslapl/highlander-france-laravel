<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Téléchargements privés : fichiers volumineux (ZIP) servis sous /dl/<token>
 * uniquement à ceux qui connaissent le lien.
 *
 * Chaque fichier est enregistré dans <downloads>/<token>/<nom>.<ext>, le token
 * (32 caractères hexadécimaux) servant de secret dans l'URL. L'enregistrement
 * est fait par la commande app:downloads:add — le fichier est d'abord déposé
 * sur le serveur (SCP) hors webroot.
 */
final class DownloadsService
{
    /** Format d'un token : 32 hexadécimaux (128 bits de hasard). */
    public const TOKEN_REGEX = '/^[a-f0-9]{32}$/';

    /**
     * Répertoire racine des téléchargements (créé si nécessaire).
     */
    public function dir(): string
    {
        $dir = rtrim((string) config('hlfr.downloads.dir', ''), '/\\');
        if ($dir === '') {
            $dir = hlfr_data_path('downloads');
        }

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Répertoire d'un token donné.
     */
    public function dirFor(string $token): string
    {
        return $this->dir().DIRECTORY_SEPARATOR.$token;
    }

    /**
     * Chemin absolu du fichier servi pour un token, ou null si absent/invalide.
     */
    public function resolve(string $token): ?string
    {
        if (preg_match(self::TOKEN_REGEX, $token) !== 1) {
            return null;
        }

        $dir = $this->dirFor($token);
        if (! is_dir($dir)) {
            return null;
        }

        foreach ($this->files($dir) as $file) {
            return $file;
        }

        return null;
    }

    /**
     * Enregistre un fichier déjà présent sur le serveur : le déplace dans
     * <downloads>/<token>/ et génère un token secret. Retourne les infos du lien.
     *
     * @return array{token: string, url: string, file: string, name: string, size: int}
     */
    public function register(string $source): array
    {
        $source = realpath($source);
        if ($source === false || ! is_file($source)) {
            throw new \InvalidArgumentException("Fichier introuvable : {$source}");
        }

        $ext = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        if (! in_array($ext, $this->extensions(), true)) {
            throw new \InvalidArgumentException(
                "Extension .{$ext} interdite (autorisées : ".implode(', ', $this->extensions()).').'
            );
        }

        $token = $this->generateToken();
        $targetDir = $this->dirFor($token);
        if (! is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $name = (string) basename($source);
        $target = $targetDir.DIRECTORY_SEPARATOR.$name;

        if ($source !== $target && ! @rename($source, $target)) {
            // Changement de système de fichiers : copie puis suppression.
            $fp = @fopen($source, 'rb');
            $out = @fopen($target, 'wb');
            if ($fp === false || $out === false || stream_copy_to_stream($fp, $out) === false) {
                if (is_resource($fp)) {
                    fclose($fp);
                }
                if (is_resource($out)) {
                    fclose($out);
                }
                throw new \RuntimeException("Impossible de déplacer le fichier vers {$target}.");
            }
            fclose($fp);
            fclose($out);
            @unlink($source);
        }

        return [
            'token' => $token,
            'url' => url('dl/'.$token),
            'file' => $target,
            'name' => $name,
            'size' => (int) filesize($target),
        ];
    }

    /**
     * Liste des téléchargements enregistrés, triés par nom de fichier.
     *
     * @return array<int, array{token: string, url: string, name: string, size: int}>
     */
    public function all(): array
    {
        $rows = [];

        foreach (glob($this->dir().DIRECTORY_SEPARATOR.'*') ?: [] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $token = (string) basename($dir);
            if (preg_match(self::TOKEN_REGEX, $token) !== 1) {
                continue;
            }

            $files = $this->files($dir);
            if ($files === []) {
                continue;
            }

            $file = $files[0];

            $rows[] = [
                'token' => $token,
                'url' => url('dl/'.$token),
                'name' => (string) basename($file),
                'size' => (int) filesize($file),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Révoque un lien : supprime le dossier du token. Retourne false si absent.
     */
    public function revoke(string $token): bool
    {
        if (preg_match(self::TOKEN_REGEX, $token) !== 1) {
            return false;
        }

        $dir = $this->dirFor($token);
        if (! is_dir($dir)) {
            return false;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        return @rmdir($dir);
    }

    /**
     * Taille humaine (o, Ko, Mo, Go, To) pour l'affichage en console.
     */
    public static function humanSize(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];

        $size = (float) $bytes;
        foreach ($units as $unit) {
            if ($size < 1024 || $unit === $units[array_key_last($units)]) {
                break;
            }
            $size /= 1024;
        }

        return number_format($size, $size >= 100 || $unit === 'o' ? 0 : 1).' '.$unit;
    }

    /**
     * Extensions autorisées (whitelist).
     *
     * @return list<string>
     */
    private function extensions(): array
    {
        $exts = config('hlfr.downloads.extensions', ['zip']);
        $exts = is_array($exts) ? $exts : ['zip'];

        return array_values(array_map(static fn ($e): string => strtolower(trim((string) $e)), $exts));
    }

    /**
     * Fichiers (non dossiers) du répertoire, limités aux extensions autorisées.
     *
     * @return list<string>
     */
    private function files(string $dir): array
    {
        $files = [];

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, $this->extensions(), true)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Génère un token unique (32 hexadécimaux).
     */
    private function generateToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (is_dir($this->dirFor($token)));

        return $token;
    }
}
