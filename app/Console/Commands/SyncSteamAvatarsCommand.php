<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdminLogger;
use App\Services\Crons\SyncSteamAvatarsService;
use Illuminate\Console\Command;

final class SyncSteamAvatarsCommand extends Command
{
    protected $signature = 'app:sync-steam-avatars';

    protected $description = 'Répare les profils Steam cassés (avatars/pseudos vides)';

    public function handle(): int
    {
        set_time_limit(300);

        try {
            $output = (new SyncSteamAvatarsService())->run();
        } catch (\Throwable $e) {
            AdminLogger::log('sync_steam_avatars.php', null, 'FAILED (' . $e->getMessage() . ')');
            $this->error('Erreur lors de la réparation des profils Steam : ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info($output);

        return self::SUCCESS;
    }
}
