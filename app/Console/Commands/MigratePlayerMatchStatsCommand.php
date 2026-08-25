<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\MigratePlayerMatchStatsService;
use Illuminate\Console\Command;

final class MigratePlayerMatchStatsCommand extends Command
{
    protected $signature = 'app:migrate-player-match-stats';

    protected $description = 'Vérifie le schéma des stats de player_matches (ex-migration SQLite)';

    public function handle(): int
    {
        $this->info((new MigratePlayerMatchStatsService())->run());

        return self::SUCCESS;
    }
}
