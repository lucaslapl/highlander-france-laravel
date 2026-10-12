<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\ComputePlayerLevelsService;
use Illuminate\Console\Command;

final class ComputePlayerLevelsCommand extends Command
{
    protected $signature = 'app:compute-player-levels';

    protected $description = 'Calcule l\'expérience ETF2L des joueurs inscrits selon la règle 2.7 (3 dernières saisons actives, decay et conversion croisée 9v9/6v6 incluses)';

    public function handle(): int
    {
        set_time_limit(300);
        $this->info((new ComputePlayerLevelsService())->run());

        return self::SUCCESS;
    }
}
