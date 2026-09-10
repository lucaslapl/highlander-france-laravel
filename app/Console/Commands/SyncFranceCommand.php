<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdminLogger;
use App\Services\Crons\SyncFranceService;
use Illuminate\Console\Command;

final class SyncFranceCommand extends Command
{
    protected $signature = 'app:sync-france';

    protected $description = 'Synchronise les rosters Équipe de France 6v6 et Highlander (badges)';

    public function handle(): int
    {
        set_time_limit(120);

        try {
            $output = (new SyncFranceService)->run();
        } catch (\Throwable $e) {
            AdminLogger::log('sync_france.php', null, 'FAILED (' . $e->getMessage() . ')');
            $this->error('Erreur lors de la synchronisation des rosters : ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info($output);

        return self::SUCCESS;
    }
}
