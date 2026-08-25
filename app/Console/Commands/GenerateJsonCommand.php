<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crons\GenerateJsonService;
use Illuminate\Console\Command;

final class GenerateJsonCommand extends Command
{
    protected $signature = 'app:generate-json';

    protected $description = 'Génère les caches JSON du classement (leaderboard)';

    public function handle(): int
    {
        set_time_limit(300);
        $this->info((new GenerateJsonService())->run());

        return self::SUCCESS;
    }
}
