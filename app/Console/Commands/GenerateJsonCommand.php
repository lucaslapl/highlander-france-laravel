<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdminLogger;
use App\Services\Crons\GenerateJsonService;
use Illuminate\Console\Command;

final class GenerateJsonCommand extends Command
{
    protected $signature = 'app:generate-json';

    protected $description = 'Génère les caches JSON du classement (leaderboard)';

    public function handle(): int
    {
        set_time_limit(300);

        try {
            $output = (new GenerateJsonService())->run();
        } catch (\Throwable $e) {
            AdminLogger::log('generate_json.php', null, 'FAILED (' . $e->getMessage() . ')');
            $this->error('Erreur lors de la génération des caches JSON : ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info($output);

        return self::SUCCESS;
    }
}
