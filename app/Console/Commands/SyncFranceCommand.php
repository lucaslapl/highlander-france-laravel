<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\SyncFranceService;
use Illuminate\Console\Command;

final class SyncFranceCommand extends Command
{
    protected $signature = 'app:sync-france';

    protected $description = 'Synchronise les rosters Équipe de France 6v6 et Highlander (badges)';

    public function handle(): int
    {
        set_time_limit(120);
        $this->info((new SyncFranceService)->run());

        return self::SUCCESS;
    }
}
