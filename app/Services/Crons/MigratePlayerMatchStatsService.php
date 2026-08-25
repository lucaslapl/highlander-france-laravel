<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;

/**
 * Vérification des colonnes de stats de player_matches.
 *
 * Sous l'ancien MVC, ce script ajoutait à la main des colonnes à SQLite
 * (pas d'ALTER complet) ; sous Laravel, le schéma est géré par les
 * migrations : cette commande ne sert plus que de vérification.
 */
final class MigratePlayerMatchStatsService
{
    private const SCRIPT_NAME = 'migrate_player_match_stats.php';

    private const EXPECTED_COLUMNS = [
        'dmg', 'kills', 'deaths', 'assists', 'suicides', 'heal', 'medkits',
        'ubers', 'drops', 'backstabs', 'headshots', 'longest_killstreak',
        'classes_killed', 'length', 'dapm', 'dmg_taken', 'medkits_hp',
        'airshots', 'captures', 'won',
    ];

    public function run(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        $schemaManager = \Illuminate\Support\Facades\Schema::getColumnListing('player_matches');
        $missing = array_diff(self::EXPECTED_COLUMNS, $schemaManager);

        if ($missing !== []) {
            AdminLogger::log(self::SCRIPT_NAME, $logToken, 'FAILED (colonnes manquantes : ' . implode(', ', $missing) . ')');

            throw new \RuntimeException(
                'Colonnes manquantes dans player_matches : ' . implode(', ', $missing)
                . '. Relancez `php artisan migrate`.'
            );
        }

        AdminLogger::log(self::SCRIPT_NAME, $logToken, 'SUCCESS (schéma déjà à jour, 0 colonne ajoutée)');

        return "Vérification terminée : le schéma de player_matches est déjà à jour (0 colonne ajoutée).";
    }
}
