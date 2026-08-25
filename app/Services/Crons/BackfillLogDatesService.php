<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Models\MatchStatsRepository;
use App\Services\AdminLogger;
use App\Services\JsonClient;
use Illuminate\Support\Facades\DB;

/**
 * Backfill des dates de matchs manquantes (app:backfill-log-dates).
 */
final class BackfillLogDatesService
{
    private const SCRIPT_NAME = 'backfill_log_dates.php';

    private const LOGS_TF_URLS = [
        'https://logs.tf/api/v1/log?title=Highlander%20France&limit=200',
        'https://logs.tf/api/v1/log?title=highlanderfrance.tf&limit=200',
    ];

    private \PDO $db;

    public function __construct()
    {
        $this->db = DB::connection()->getPdo();
    }

    public function run(): string
    {
        $logToken = AdminLogger::log(self::SCRIPT_NAME);

        $dataOld = JsonClient::get(self::LOGS_TF_URLS[0]);
        $dataNew = JsonClient::get(self::LOGS_TF_URLS[1]);

        if ($dataOld === null && $dataNew === null) {
            throw new \RuntimeException("Impossible de récupérer l'index initial sur logs.tf");
        }

        $repo = new MatchStatsRepository();

        $dates = [];
        foreach (array_merge($dataOld['logs'] ?? [], $dataNew['logs'] ?? []) as $l) {
            if (isset($l['id'])) {
                $dates[$l['id']] = (int) ($l['date'] ?? 0);
            }
        }

        $covered = 0;
        foreach ($dates as $id => $date) {
            $repo->saveLogDate($id, $date);
            $covered++;
        }

        // Compléter les trous : matchs en base sans date (fetch détail individuel).
        $missing = $this->db->query("
            SELECT DISTINCT pm.match_id
            FROM player_matches pm
            LEFT JOIN log_dates ld ON ld.log_id = pm.match_id
            WHERE ld.log_id IS NULL
        ")->fetchAll(\PDO::FETCH_COLUMN);

        $fetched = 0;
        foreach ($missing as $matchId) {
            $details = JsonClient::get('https://logs.tf/api/v1/log/' . (int) $matchId);
            if ($details !== null && isset($details['date'])) {
                $repo->saveLogDate((int) $matchId, (int) $details['date']);
                $fetched++;
                usleep(300000);
            }
        }

        $remaining = count($missing) - $fetched;
        AdminLogger::log(self::SCRIPT_NAME, $logToken,
            "SUCCESS ($covered dates depuis l'index, $fetched complétées, $remaining manquantes)");

        return "Backfill terminé : $covered depuis l'index, $fetched complétées individuellement, $remaining manquantes.";
    }
}
