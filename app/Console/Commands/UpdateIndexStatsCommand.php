<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdminLogger;
use App\Services\Crons\UpdateIndexStatsService;
use Illuminate\Console\Command;

final class UpdateIndexStatsCommand extends Command
{
    protected $signature = 'app:update-index-stats';

    protected $description = 'Met à jour les statistiques de la page d\'accueil';

    public function handle(): int
    {
        set_time_limit(300);

        try {
            $output = (new UpdateIndexStatsService())->run();
        } catch (\Throwable $e) {
            AdminLogger::log('update_index_stats.php', null, 'FAILED (' . $e->getMessage() . ')');
            $this->error('Erreur lors de la mise à jour des stats d\'accueil : ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info($output);

        return self::SUCCESS;
    }
}
