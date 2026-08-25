<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;
use App\Services\SteamApi;
use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

/**
 * Synchronisation des profils Steam "cassés" (app:sync-steam-avatars).
 */
final class SyncSteamAvatarsService
{
    private const SCRIPT_NAME = 'sync_steam_avatars.php';

    private \PDO $db;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    public function run(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        // NB : le legacy contenait `display_name IS 'Nouveau Joueur'` (bug SQLite
        // tolérant) ; corrigé en `=` ici.
        $players = $this->db->query("
            SELECT steamid
            FROM players_info
            WHERE name = 'Nouveau Joueur'
               OR name IS NULL
               OR name = ''
               OR display_name = 'Nouveau Joueur'
               OR avatar IS NULL
               OR avatar = ''
        ")->fetchAll(\PDO::FETCH_ASSOC);

        if ($players === []) {
            AdminLogger::log(self::SCRIPT_NAME, $logToken, 'SUCCESS (Tous les profils étaient déjà à jour)');

            return 'Aucun joueur ne nécessite de synchronisation. Tous les profils sont à jour !';
        }

        $successCount = 0;
        $errorCount = 0;
        $output = "Found " . count($players) . " joueur(s) à synchroniser.\n--------------------------------------------------------\n";

        $steamApi = new SteamApi();
        $checkName = $this->db->prepare('SELECT name FROM players_info WHERE steamid = ?');

        foreach ($players as $player) {
            $steamid3 = (string) $player['steamid'];

            $steamid64 = SteamId::toSteamId64($steamid3);
            if ($steamid64 === null) {
                $output .= "[ERREUR] Impossible de convertir le SteamID3 '{$steamid3}' en SteamID64.\n";
                $errorCount++;
                continue;
            }

            $output .= "Synchronisation de {$steamid3} (SteamID64: {$steamid64})... ";

            if ($steamApi->syncOrCreatePlayer($steamid64)) {
                $checkName->execute([$steamid3]);
                $updatedName = $checkName->fetchColumn();

                $output .= "SUCCES (Nouveau pseudo : '{$updatedName}')\n";
                $successCount++;
            } else {
                $output .= "ECHEC (API Steam injoignable ou cle invalide)\n";
                $errorCount++;
            }

            usleep(200000);
        }

        $output .= "--------------------------------------------------------\n";
        $output .= "Joueurs mis à jour avec succès : {$successCount}\n";
        $output .= "Échecs ou erreurs : {$errorCount}\n";

        $statusMsg = 'SUCCESS (' . $successCount . ' synchronisés, ' . $errorCount . ' échecs)';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        return $output;
    }
}
