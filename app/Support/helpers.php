<?php

declare(strict_types=1);

/**
 * Helpers globaux (port de config/autoload.php de l'ancien MVC).
 * Chargés via l'autoload Composer ("files" dans composer.json).
 */

use Illuminate\Support\Facades\Request;

if (! function_exists('site_url')) {
    /**
     * URL de base du site (sans slash final).
     * Priorité : variable d'environnement APP_URL, puis hôte de la requête.
     */
    function site_url(): string
    {
        $configured = (string) config('app.url', '');

        if ($configured !== '' && app()->runningInConsole()) {
            return rtrim($configured, '/');
        }

        $host = Request::getHttpHost();

        if ($host === '' || $host === null) {
            return rtrim($configured, '/');
        }

        $scheme = Request::getScheme();

        return $scheme.'://'.$host;
    }
}

if (! function_exists('current_url')) {
    /**
     * URL complète de la page courante (chemin + query string).
     */
    function current_url(): string
    {
        return site_url().Request::getRequestUri();
    }
}

if (! function_exists('canonical_url')) {
    function canonical_url(): string
    {
        $path = Request::getPathInfo();
        $base = site_url().($path !== '' ? $path : '/');
        $page = (int) Request::query('page', 0);
        if ($page > 1 && in_array($path, ['/matchs', '/joueurs'], true)) {
            return $base.'?page='.$page;
        }

        return $base;
    }
}

if (! function_exists('site_description')) {
    /**
     * Description par défaut du site (meta description / fallback de contenu).
     */
    function site_description(): string
    {
        return config('app.name').' est une communauté compétitive francophone de Team Fortress 2, offrant un espace pour les joueurs de tous niveaux pour apprendre, jouer et progresser ensemble.';
    }
}

if (! function_exists('e')) {
    /**
     * Échappe une chaîne pour du HTML (compat ancien code).
     * Blade échappe déjà via {{ }} ; ce helper sert aux templates non-Blade
     * et aux constructions HTML en PHP.
     */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('hlfr_asset')) {
    /**
     * Chemin d'asset versionné (bust de cache par date de modification).
     * Ex. : /_css/main.css?v=1724000000
     */
    function hlfr_asset(string $path): string
    {
        $file = public_path($path);
        $version = is_file($file) ? (string) filemtime($file) : '0';

        return $path.'?v='.$version;
    }
}

if (! function_exists('hlfr_data_path')) {
    /**
     * Chemin absolu d'un fichier du répertoire de données applicatives
     * (caches JSON, logs CRON, état des matchs live).
     *
     * Le répertoire est créé automatiquement s'il n'existe pas (ce qui n'était
     * pas garanti sur un déploiement à froid, d'où des caches « absents »).
     */
    function hlfr_data_path(string $file = ''): string
    {
        $dir = rtrim((string) config('hlfr.data_dir'), '/\\');

        // Chemin relatif (ex. HLFR_DATA_DIR=storage/app/hlfr) : l'ancrer à la
        // racine de l'app, sinon la résolution dépend du répertoire de travail
        // courant (CLI = racine, PHP-FPM = docroot) et le web lit un autre
        // répertoire que celui écrit par le CRON.
        if ($dir !== '' && ! preg_match('#^(?:[a-zA-Z]:[/\\\\]|/)#', $dir)) {
            $dir = base_path($dir);
        }

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $file === '' ? $dir : $dir.DIRECTORY_SEPARATOR.$file;
    }
}
