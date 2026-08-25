<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\BackfillMatchTeamsService;
use Illuminate\Console\Command;

final class BackfillMatchTeamsCommand extends Command
{
    protected $signature = 'app:backfill-match-teams';

    protected $description = 'Backfill des équipes et scores de match manquants';

    public function handle(): int
    {
        set_time_limit(600);
        $this->info((new BackfillMatchTeamsService())->run());

        return self::SUCCESS;
    }
}
