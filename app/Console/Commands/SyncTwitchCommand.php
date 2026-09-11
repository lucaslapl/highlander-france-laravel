<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TwitchLive;
use Illuminate\Console\Command;

final class SyncTwitchCommand extends Command
{
    protected $signature = 'app:sync-twitch';

    protected $description = 'Rafraîchit le cache des chaînes Twitch en direct';

    public function handle(): int
    {
        set_time_limit(60);

        $result = TwitchLive::refresh();

        if (str_starts_with($result, 'SUCCESS')) {
            $this->info($result);
        } else {
            $this->warn($result);
        }

        return self::SUCCESS;
    }
}
