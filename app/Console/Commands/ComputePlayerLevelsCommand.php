<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\ComputePlayerLevelsService;
use Illuminate\Console\Command;

final class ComputePlayerLevelsCommand extends Command
{
    protected $signature = 'app:compute-player-levels';

    protected $description = 'Calcule le niveau réel des joueurs inscrits depuis leurs résultats ETF2L (3 dernières saisons par mode, matchs de remplacement exclus)';

    public function handle(): int
    {
        set_time_limit(300);
        $this->info((new ComputePlayerLevelsService())->run());

        return self::SUCCESS;
    }
}
