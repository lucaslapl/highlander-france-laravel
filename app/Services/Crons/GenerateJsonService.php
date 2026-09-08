<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Services\AdminLogger;
use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

/**
 * Génération des caches JSON de classement (leaderboard) (app:generate-json).
 */
final class GenerateJsonService
{
    private const SCRIPT_NAME = 'generate_json.php';

    /** Verrou anti-concurrence : une seule exécution à la fois (cron + webhook + panel admin). */
    private const LOCK_FILE = 'generate_json.lock';

    private const MIN_MATCHES = 5;

    private \PDO $db;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    public function run(): string
    {
        $lock = fopen(hlfr_data_path(self::LOCK_FILE), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return 'Génération JSON ignorée : une autre exécution est déjà en cours.';
        }

        try {
            return $this->doRun();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function doRun(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        $modes = ['6s', '9v9'];
        $updatedModes = [];

        $categories = [
            'matches' => [
                'value_key' => 'count',
                'suffix'    => '',
                'sql'       => "SELECT COALESCE(p.display_name, p.name) AS name,
                                       p.avatar, p.steamid, s.count AS value
                                FROM player_stats s
                                JOIN players_info p ON s.steamid = p.steamid
                                WHERE s.game_mode = ?
                                ORDER BY s.count DESC LIMIT 18",
            ],
            'kills' => [
                'value_key' => 'value',
                'suffix'    => '_kills',
                // NB : toutes les colonnes non aggregees figurent dans le
                // GROUP BY (compatibilite ONLY_FULL_GROUP_BY / MariaDB).
                // Le join est 1:1 (steamid = cle primaire), le resultat est
                // donc identique a un groupement par steamid seul.
                'sql'       => "SELECT COALESCE(p.display_name, p.name) AS name,
                                       p.avatar, p.steamid, SUM(pm.kills) AS value
                                FROM player_matches pm
                                JOIN players_info p ON p.steamid = pm.steamid
                                WHERE pm.game_mode = ? AND p.created_at IS NOT NULL
                                GROUP BY pm.steamid, p.steamid, p.display_name, p.name, p.avatar
                                HAVING COUNT(*) >= " . self::MIN_MATCHES . "
                                ORDER BY value DESC LIMIT 18",
            ],
            'heal' => [
                'value_key' => 'value',
                'suffix'    => '_heal',
                'sql'       => "SELECT COALESCE(p.display_name, p.name) AS name,
                                       p.avatar, p.steamid, SUM(pm.heal) AS value
                                FROM player_matches pm
                                JOIN players_info p ON p.steamid = pm.steamid
                                WHERE pm.game_mode = ? AND p.created_at IS NOT NULL
                                GROUP BY pm.steamid, p.steamid, p.display_name, p.name, p.avatar
                                HAVING COUNT(*) >= " . self::MIN_MATCHES . "
                                ORDER BY value DESC LIMIT 18",
            ],
            'dpm' => [
                'value_key' => 'value',
                'suffix'    => '_dpm',
                'sql'       => "SELECT COALESCE(p.display_name, p.name) AS name,
                                       p.avatar, p.steamid,
                                       AVG(CASE WHEN pm.length > 0 THEN pm.dapm END) AS value
                                FROM player_matches pm
                                JOIN players_info p ON p.steamid = pm.steamid
                                WHERE pm.game_mode = ? AND p.created_at IS NOT NULL
                                GROUP BY pm.steamid, p.steamid, p.display_name, p.name, p.avatar
                                HAVING COUNT(*) >= " . self::MIN_MATCHES . " AND value IS NOT NULL
                                ORDER BY value DESC LIMIT 18",
            ],
        ];

        foreach ($modes as $mode) {
            foreach ($categories as $category) {
                $stmt = $this->db->prepare($category['sql']);
                $stmt->execute([$mode]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $finalResults = [];
                foreach ($rows as $row) {
                    $steamid64 = SteamId::toSteamId64((string) $row['steamid']);
                    $finalResults[] = [
                        'name' => $row['name'],
                        'avatar' => $steamid64 !== null ? \App\Services\AvatarCache::urlFor($steamid64) : ($row['avatar'] ?? ''),
                        'steamid' => $steamid64,
                        $category['value_key'] => $row['value'],
                    ];
                }

                $filePath = hlfr_data_path('leaderboard_cache_' . $mode . $category['suffix'] . '.json');
                $writeResult = file_put_contents($filePath, json_encode($finalResults), LOCK_EX);

                if ($writeResult === false) {
                    throw new \RuntimeException("Impossible d'écrire le fichier de cache JSON pour le mode : " . $mode);
                }

                $updatedModes[] = $mode . $category['suffix'];
            }
        }

        $statusMsg = 'SUCCESS (Classements mis à jour : ' . implode(', ', $updatedModes) . ')';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $statusMsg);

        file_put_contents(hlfr_data_path('log_generate_json.txt'), date('Y-m-d H:i:s') . " OK\n", FILE_APPEND);

        return 'Cache mis à jour avec succès.';
    }
}
