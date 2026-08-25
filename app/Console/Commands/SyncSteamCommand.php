<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\SyncSteamService;
use Illuminate\Console\Command;

final class SyncSteamCommand extends Command
{
    protected $signature = 'app:sync-steam';

    protected $description = 'Importe les profils Steam manquants';

    public function handle(): int
    {
        set_time_limit(300);
        $this->info((new SyncSteamService())->run());

        return self::SUCCESS;
    }
}
