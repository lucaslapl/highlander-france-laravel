<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * État d'authentification (session) — port du service Auth de l'ancien MVC.
 * La session est gérée par Laravel ; on expose la même API statique pour
 * ne pas réécrire toute la logique métier existante.
 */
final class Auth
{
    public static function steamId64(): ?string
    {
        $value = Session::get('steamid');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function isLoggedIn(): bool
    {
        return self::steamId64() !== null;
    }

    public static function isAdmin(): bool
    {
        return Session::get('is_admin', false) === true;
    }

    /**
     * Bloque l'accès si le visiteur n'est pas un administrateur authentifié
     * (à utiliser derrière le middleware admin, en filet de sécurité).
     */
    public static function requireAdmin(): void
    {
        if (self::isAdmin() && self::steamId64() !== null) {
            return;
        }

        abort(403, 'Accès refusé.');
    }

    /**
     * Profil complet du joueur connecté (table players_info), ou null.
     */
    public static function user(): ?object
    {
        $steamid64 = self::steamId64();

        if ($steamid64 === null) {
            return null;
        }

        $user = DB::table('players_info')
            ->where('steamid', SteamId::toSteamId3($steamid64))
            ->first();

        return $user ?: null;
    }
}
