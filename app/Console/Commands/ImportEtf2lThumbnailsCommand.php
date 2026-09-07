<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Etf2lThumbnailsImporter;
use Illuminate\Console\Command;

final class ImportEtf2lThumbnailsCommand extends Command
{
    protected $signature = 'maps:import-thumbnails
                            {--force : Re-télécharge les vignettes déjà présentes}';

    protected $description = 'Importe les miniatures de maps depuis etf2l.org/maps';

    public function handle(): int
    {
        set_time_limit(600);

        try {
            $result = (new Etf2lThumbnailsImporter())->importAll((bool) $this->option('force'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $table = [];
        foreach (['matched_exact' => 'match exact', 'matched_base' => 'match base', 'skipped' => 'sans vignette'] as $key => $label) {
            foreach ($result[$key] as $name) {
                $table[] = [$name, $label];
            }
        }

        $this->table(['Map', 'Statut'], $table);

        $this->info(
            count($result['matched_exact']) + count($result['matched_base']) . ' vignette(s) importée(s)'
        );
        if ($result['skipped'] !== []) {
            $this->warn('Sans vignette : ' . implode(', ', $result['skipped']) . ' (import manuel possible via le panel)');
        }

        return self::SUCCESS;
    }
}