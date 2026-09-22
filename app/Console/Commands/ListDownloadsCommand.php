<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DownloadsService;
use Illuminate\Console\Command;

/**
 * Liste les téléchargements privés enregistrés (token, fichier, taille, lien).
 */
final class ListDownloadsCommand extends Command
{
    protected $signature = 'app:downloads:list';

    protected $description = 'Liste les téléchargements privés enregistrés';

    public function handle(DownloadsService $downloads): int
    {
        $rows = $downloads->all();

        if ($rows === []) {
            $this->info('Aucun téléchargement enregistré.');

            return self::SUCCESS;
        }

        $this->table(
            ['Token', 'Lien secret', 'Fichier', 'Taille'],
            array_map(static fn (array $row): array => [
                $row['token'],
                $row['url'],
                $row['name'],
                DownloadsService::humanSize($row['size']),
            ], $rows)
        );

        return self::SUCCESS;
    }
}
