<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdminLogger;
use App\Services\Crons\UpdateStatsService;
use Illuminate\Console\Command;

final class UpdateStatsCommand extends Command
{
    protected $signature = 'app:update-stats';

    protected $description = 'Met à jour les statistiques des matchs joueurs (logs.tf)';

    public function handle(): int
    {
        set_time_limit(300);

        try {
            $output = (new UpdateStatsService())->run();
        } catch (\Throwable $e) {
            AdminLogger::log('update_stats.php', null, 'FAILED (' . $e->getMessage() . ')');
            $this->error('Erreur lors de la mise à jour des stats : ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info($output);

        return self::SUCCESS;
    }
}
