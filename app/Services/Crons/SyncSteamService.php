<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;
use App\Services\JsonClient;
use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

/**
 * Synchronisation des profils Steam manquants (app:sync-steam).
 */
final class SyncSteamService
{
    private const SCRIPT_NAME = 'sync_steam.php';

    private \PDO $db;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    public function run(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);
        $historyFile = hlfr_data_path('log_sync_steam.txt');

        $apiKey = (string) env('STEAM_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException("Clé d'API Steam manquante dans le fichier .env");
        }

        $missing = $this->db
            ->query("SELECT DISTINCT s.steamid
                     FROM player_stats s
                     LEFT JOIN players_info p ON s.steamid = p.steamid
                     WHERE p.steamid IS NULL")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $logMsg = function (string $msg) use ($historyFile): void {
            file_put_contents($historyFile, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
        };

        if ($missing === []) {
            $logMsg('Aucun nouveau profil à traiter.');
            AdminLogger::log(self::SCRIPT_NAME, $logToken, 'SUCCESS (Aucun profil à synchroniser)');

            return "Aucun nouveau profil à traiter. \n";
        }

        $logMsg("Nombre d'IDs à traiter : " . count($missing));

        $chunks = array_chunk($missing, 100);
        $profilesAdded = 0;

        foreach ($chunks as $chunk) {
            $ids64 = [];
            foreach ($chunk as $steamid3) {
                $converted = SteamId::toSteamId64((string) $steamid3);
                if ($converted !== null) {
                    $ids64[] = $converted;
                }
            }

            $idsParam = implode(',', $ids64);
            $url = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=' . $apiKey . '&steamids=' . $idsParam;

            $data = JsonClient::get($url, 15);
            if ($data === null) {
                $logMsg("Erreur API Steam pour chunk : $idsParam");
                continue;
            }

            if (!isset($data['response']['players'])) {
                $logMsg("Réponse Steam invalide pour chunk : $idsParam");
                continue;
            }

            $insert = $this->db->prepare('INSERT IGNORE INTO players_info (steamid, name, avatar, last_updated) VALUES (?, ?, ?, ?)');
            foreach ($data['response']['players'] as $p) {
                $originalId = SteamId::toSteamId3((string) $p['steamid']);

                $insert->execute([$originalId, (string) ($p['personaname'] ?? ''), (string) ($p['avatarfull'] ?? ''), time()]);

                $logMsg('Ajouté : ' . ($p['personaname'] ?? ''));
                $profilesAdded++;
            }

            sleep(1);
        }

        $logMsg('Synchronisation terminée avec succès.');

        $statusMsg = 'SUCCESS (' . $profilesAdded . ' profils Steam importés)';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        return 'Synchronisation terminée avec succès.';
    }
}
