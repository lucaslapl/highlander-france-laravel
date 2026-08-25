<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\SyncEtf2lService;
use Illuminate\Console\Command;

final class SyncEtf2lCommand extends Command
{
    protected $signature = 'app:sync-etf2l
                            {--history-days=7 : Fenêtre d\'historique en jours}';

    protected $description = 'Synchronise l\'agenda des matchs ETF2L français';

    public function handle(): int
    {
        set_time_limit(300);
        $this->info((new SyncEtf2lService())->run(max(1, (int) $this->option('history-days'))));

        return self::SUCCESS;
    }
}
