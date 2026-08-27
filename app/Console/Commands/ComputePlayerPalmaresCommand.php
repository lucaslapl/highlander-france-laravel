<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\ComputePlayerPalmaresService;
use Illuminate\Console\Command;

final class ComputePlayerPalmaresCommand extends Command
{
    protected $signature = 'app:compute-player-palmares';

    protected $description = 'Calcule le palmarès ETF2L de tous les joueurs inscrits (classements finaux + playoffs significatifs)';

    public function handle(): int
    {
        set_time_limit(600);
        $this->info((new ComputePlayerPalmaresService())->run());

        return self::SUCCESS;
    }
}
