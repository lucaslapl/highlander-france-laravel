<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Models\MatchLogRepository;
use App\Services\AdminLogger;
use App\Services\JsonClient;
use Illuminate\Support\Facades\DB;

/**
 * Mise à jour des stats de la page d'accueil (app:update-index-stats).
 */
final class UpdateIndexStatsService
{
    private const SCRIPT_NAME = 'update_index_stats.php';

    private const LOGS_TF_URLS = [
        'https://logs.tf/api/v1/log?title=Highlander%20France',
        'https://logs.tf/api/v1/log?title=highlanderfrance.tf',
    ];

    private \PDO $db;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    public function run(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        $blacklistRepo = new MatchLogRepository();
        $blacklist = $blacklistRepo->blacklistedIds();

        $responseOld = JsonClient::get(self::LOGS_TF_URLS[0]);
        $responseNew = JsonClient::get(self::LOGS_TF_URLS[1]);

        if ($responseOld === null && $responseNew === null) {
            throw new \RuntimeException("Impossible de contacter l'API logs.tf (les deux endpoints ont échoué).");
        }

        $mergedLogs = [];
        foreach (array_merge($responseOld['logs'] ?? [], $responseNew['logs'] ?? []) as $l) {
            if (isset($l['id'])) {
                $mergedLogs[$l['id']] = $l;
            }
        }

        $filteredLogs = array_filter($mergedLogs, static fn (array $log): bool => !in_array($log['id'], $blacklist, true));

        // Tri par ID décroissant.
        usort($filteredLogs, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);

        // Retrait des 4 plus anciens de la liste.
        if (count($filteredLogs) > 4) {
            $filteredLogs = array_slice($filteredLogs, 0, -4);
        }

        if ($filteredLogs === []) {
            throw new \RuntimeException('Aucun match trouvé après filtrage et blacklist.');
        }

        $minMatchLength = (int) config('hlfr.min_match_length', 300);
        $cacheStmt = $this->db->prepare('SELECT length FROM matches_cache WHERE match_id = ?');
        $insStmt = $this->db->prepare('INSERT INTO matches_cache (match_id, length) VALUES (?, ?)
                                       ON DUPLICATE KEY UPDATE length = VALUES(length)');

        foreach ($filteredLogs as $log) {
            $matchId = (int) $log['id'];

            $cacheStmt->execute([$matchId]);

            if ($cacheStmt->fetch()) {
                continue;
            }

            $details = JsonClient::get('https://logs.tf/api/v1/log/' . $matchId);
            if ($details === null) {
                error_log("Erreur 502/404 pour le match $matchId - On passe au suivant.");
                continue;
            }

            $insStmt->execute([$matchId, (int) ($details['length'] ?? 0)]);
            usleep(200000);
        }

        // Auto-blacklist : les logs de moins de 5 minutes sont exclus des stats de l'accueil.
        $shortLogIds = $this->db
            ->query('SELECT match_id FROM matches_cache WHERE length > 0 AND length < ' . $minMatchLength)
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($shortLogIds as $shortId) {
            $blacklistRepo->blacklist((int) $shortId, 'Durée inférieure à 5 minutes (blacklist automatique)', 'auto');
        }

        if ($shortLogIds !== []) {
            $filteredLogs = array_values(array_filter($filteredLogs, static fn (array $log): bool => !in_array($log['id'], $shortLogIds, true)));
        }

        $placeholders = implode(',', array_fill(0, count($filteredLogs), '?'));
        $idsFiltres = array_column($filteredLogs, 'id');

        $stmtFinal = $this->db->prepare("SELECT COUNT(*) AS nb, SUM(length) AS total FROM matches_cache WHERE match_id IN ($placeholders)");
        $stmtFinal->execute($idsFiltres);
        $stats = $stmtFinal->fetch(\PDO::FETCH_ASSOC);

        $result = [
            'matches' => (int) $stats['nb'],
            'hours' => (int) round((float) ($stats['total'] ?? 0) / 3600),
        ];

        file_put_contents(hlfr_data_path('cache_hlfr_stats.json'), json_encode($result));
        file_put_contents(hlfr_data_path('log_update_index_stats.txt'), date('Y-m-d H:i:s') . " OK\n", FILE_APPEND);

        $successMessage = 'SUCCESS (Total: ' . (int) $stats['nb'] . ' matchs)';
        AdminLogger::log(self::SCRIPT_NAME, $logToken, $successMessage);

        return 'Mise à jour réussie : ' . (int) $stats['nb'] . ' matchs traités.';
    }
}
