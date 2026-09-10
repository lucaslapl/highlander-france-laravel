<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdminLogger;
use App\Services\TwitchLive;
use Illuminate\Console\Command;

final class SyncTwitchCommand extends Command
{
    protected $signature = 'app:sync-twitch';

    protected $description = 'Rafraîchit le cache des chaînes Twitch en direct';

    public function handle(): int
    {
        set_time_limit(60);

        try {
            $result = TwitchLive::refresh();
        } catch (\Throwable $e) {
            AdminLogger::log('sync_twitch.php', null, 'FAILED (' . $e->getMessage() . ')');
            $this->error('Erreur Twitch : ' . $e->getMessage());

            return self::FAILURE;
        }

        if (str_starts_with($result, 'SUCCESS')) {
            AdminLogger::log('sync_twitch.php', null, $result);
            $this->info($result);
        } else {
            AdminLogger::skipped('sync_twitch.php', $result);
            $this->warn($result);
        }

        return self::SUCCESS;
    }
}
