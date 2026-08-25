<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\BackfillPlayerMatchStatsService;
use Illuminate\Console\Command;

final class BackfillPlayerMatchStatsCommand extends Command
{
    protected $signature = 'app:backfill-player-match-stats';

    protected $description = 'Backfill des stats de match joueurs manquantes';

    public function handle(): int
    {
        set_time_limit(600);
        $this->info((new BackfillPlayerMatchStatsService())->run());

        return self::SUCCESS;
    }
}
