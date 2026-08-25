<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\SyncSteamAvatarsService;
use Illuminate\Console\Command;

final class SyncSteamAvatarsCommand extends Command
{
    protected $signature = 'app:sync-steam-avatars';

    protected $description = 'Répare les profils Steam cassés (avatars/pseudos vides)';

    public function handle(): int
    {
        set_time_limit(300);
        $this->info((new SyncSteamAvatarsService())->run());

        return self::SUCCESS;
    }
}
