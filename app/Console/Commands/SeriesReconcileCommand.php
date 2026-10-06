<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\SeriesReconcileService;
use Illuminate\Console\Command;

final class SeriesReconcileCommand extends Command
{
    protected $signature = 'app:series-reconcile';

    protected $description = 'Réconcilie les séries de matchs en direct avec les logs logs.tf';

    public function handle(): int
    {
        set_time_limit(60);

        $result = (new SeriesReconcileService)->run();

        $this->info($result);

        return self::SUCCESS;
    }
}
