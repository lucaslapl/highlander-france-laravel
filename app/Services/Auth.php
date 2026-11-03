<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * État d'authentification (session) — port du service Auth de l'ancien MVC.
 * La session est gérée par Laravel ; on expose la même API statique pour
 * ne pas réécrire toute la logique métier existante.
 *
 * Seul le SteamID64 est conservé en session : les rôles (admin, caster,
 * prod) sont lus dans players_info à chaque vérification, afin qu'un
 * changement de rôle (ajout ou révocation par un admin) prenne effet
 * immédiatement, sans attendre la prochaine connexion du membre.
 */
final class Auth
{
    /**
     * Rôles du joueur connecté, lus dans la BDD (source de vérité).
     *
     * @return array{is_admin: bool, is_caster: bool, is_producer: bool}
     */
    private static function roles(): array
    {
        $empty = ['is_admin' => false, 'is_caster' => false, 'is_producer' => false];

        $steamid64 = self::steamId64();

        if ($steamid64 === null) {
            return $empty;
        }

        $row = DB::table('players_info')
            ->where('steamid', SteamId::toSteamId3($steamid64))
            ->select('is_admin', 'is_caster', 'is_producer')
            ->first();

        if ($row === null) {
            return $empty;
        }

        return [
            'is_admin' => (int) $row->is_admin === 1,
            'is_caster' => (int) $row->is_caster === 1,
            'is_producer' => (int) $row->is_producer === 1,
        ];
    }

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
        return self::roles()['is_admin'];
    }

    public static function isCaster(): bool
    {
        return self::roles()['is_caster'];
    }

    public static function isProducer(): bool
    {
        return self::roles()['is_producer'];
    }

    /**
     * Accès aux outils d'overlay OBS : réservé aux admins, mais aussi aux
     * rôles caster / prod (team Twitch) pour la préparation des streams.
     */
    public static function canAccessOverlayTools(): bool
    {
        if (self::steamId64() === null) {
            return false;
        }

        $roles = self::roles();

        return $roles['is_admin'] || $roles['is_caster'] || $roles['is_producer'];
    }

    /**
     * Bloque l'accès si le visiteur n'a pas le droit d'utiliser les outils
     * d'overlay OBS (à utiliser derrière le middleware overlay-tools,
     * en filet de sécurité).
     */
    public static function requireOverlayTools(): void
    {
        if (self::canAccessOverlayTools()) {
            return;
        }

        abort(403, 'Accès refusé.');
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
