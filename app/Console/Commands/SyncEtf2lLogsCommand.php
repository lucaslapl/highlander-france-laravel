<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\SyncEtf2lLogsService;
use Illuminate\Console\Command;

final class SyncEtf2lLogsCommand extends Command
{
    protected $signature = 'app:sync-etf2l-logs';

    protected $description = 'Récupère les logs.tf des matchs officiels ETF2L (scraping des pages match)';

    public function handle(): int
    {
        set_time_limit(300);
        $this->info((new SyncEtf2lLogsService)->run());

        return self::SUCCESS;
    }
}
