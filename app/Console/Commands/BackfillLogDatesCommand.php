<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\BackfillLogDatesService;
use Illuminate\Console\Command;

final class BackfillLogDatesCommand extends Command
{
    protected $signature = 'app:backfill-log-dates';

    protected $description = 'Backfill des dates de matchs manquantes (logs.tf)';

    public function handle(): int
    {
        set_time_limit(600);
        $this->info((new BackfillLogDatesService())->run());

        return self::SUCCESS;
    }
}
