<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\UpdateStatsService;
use Illuminate\Console\Command;

final class UpdateStatsCommand extends Command
{
    protected $signature = 'app:update-stats';

    protected $description = 'Met à jour les statistiques des matchs joueurs (logs.tf)';

    public function handle(): int
    {
        set_time_limit(300);
        $this->info((new UpdateStatsService())->run());

        return self::SUCCESS;
    }
}
