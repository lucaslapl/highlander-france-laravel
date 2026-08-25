<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SteamId;
use Illuminate\Support\Facades\DB;
use stdClass;

final class PlayerRepository
{
    /**
     * Tous les membres ayant au moins un rôle actif dans le staff.
     *
     * @return array<int, array<string, mixed>>
     */
    public function staffMembers(): array
    {
        return DB::table('players_info')
            ->select('steamid', 'name', 'display_name', 'avatar', 'is_founder', 'is_mentor', 'is_mixer', 'is_moderator')
            ->where(function ($q): void {
                $q->where('is_founder', 1)
                    ->orWhere('is_mentor', 1)
                    ->orWhere('is_mixer', 1)
                    ->orWhere('is_moderator', 1);
            })
            ->orderBy('display_name')
            ->orderBy('name')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public function findById(string $steamid3): ?array
    {
        $player = DB::table('players_info')->where('steamid', $steamid3)->first();

        return $player !== null ? (array) $player : null;
    }

    public function findBySteamId64(string $steamid64): ?array
    {
        return $this->findById(SteamId::toSteamId3($steamid64));
    }

    /**
     * Recherche de joueurs par pseudo / pseudo d'affichage (Hall of Fame).
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array
    {
        return DB::table('players_info')
            ->select('steamid', 'name', 'display_name', 'avatar')
            ->where(function ($q) use ($query): void {
                $q->where('name', 'like', '%' . $query . '%')
                    ->orWhere('display_name', 'like', '%' . $query . '%');
            })
            ->orderBy('display_name')
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Tous les SteamID (format steamid3) des joueurs indexés, pour le sitemap.
     *
     * @return array<int, string>
     */
    public function allSteamIds(): array
    {
        return DB::table('players_info')->orderBy('steamid')->pluck('steamid')->all();
    }

    /**
     * Insère le joueur s'il n'existe pas (idempotent).
     */
    public function createIfMissing(string $steamid3): void
    {
        $exists = DB::table('players_info')->where('steamid', $steamid3)->exists();

        if (! $exists) {
            DB::table('players_info')->insert([
                'steamid' => $steamid3,
                'display_name' => 'Nouveau Joueur',
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Renseigne created_at si vide (première connexion d'un compte ancien).
     */
    public function ensureCreatedAt(string $steamid3): void
    {
        DB::table('players_info')
            ->where('steamid', $steamid3)
            ->where(function ($q): void {
                $q->whereNull('created_at')->orWhere('created_at', '');
            })
            ->update(['created_at' => now()]);
    }

    public function hasNameChanged(string $steamid3): bool
    {
        $value = DB::table('players_info')->where('steamid', $steamid3)->value('name_changed');

        return (int) $value === 1;
    }

    /**
     * Enregistre le pseudo d'affichage (unique et définitif).
     */
    public function updateDisplayName(string $steamid3, string $name): bool
    {
        if ($this->hasNameChanged($steamid3)) {
            return false;
        }

        DB::table('players_info')
            ->where('steamid', $steamid3)
            ->update(['display_name' => $name, 'name_changed' => 1]);

        return true;
    }

    public function hasCountryLocked(string $steamid3): bool
    {
        $value = DB::table('players_info')->where('steamid', $steamid3)->value('country_locked');

        return (int) $value === 1;
    }

    /**
     * Enregistre la nationalité (unique et définitive).
     */
    public function updateCountry(string $steamid3, string $country): bool
    {
        if ($this->hasCountryLocked($steamid3)) {
            return false;
        }

        DB::table('players_info')
            ->where('steamid', $steamid3)
            ->update(['country' => $country, 'country_locked' => 1]);

        return true;
    }
}
