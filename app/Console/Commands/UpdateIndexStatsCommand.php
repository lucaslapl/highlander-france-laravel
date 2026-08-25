<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\UpdateIndexStatsService;
use Illuminate\Console\Command;

final class UpdateIndexStatsCommand extends Command
{
    protected $signature = 'app:update-index-stats';

    protected $description = 'Met à jour les statistiques de la page d\'accueil';

    public function handle(): int
    {
        set_time_limit(300);
        $this->info((new UpdateIndexStatsService())->run());

        return self::SUCCESS;
    }
}
