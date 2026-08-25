<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\BackfillEtf2lHistoryService;
use Illuminate\Console\Command;

final class BackfillEtf2lHistoryCommand extends Command
{
    protected $signature = 'app:backfill-etf2l-history';

    protected $description = 'Backfill ponctuel de l\'historique ETF2L complet (180 jours)';

    public function handle(): int
    {
        set_time_limit(600);
        $this->info((new BackfillEtf2lHistoryService())->run());

        return self::SUCCESS;
    }
}
