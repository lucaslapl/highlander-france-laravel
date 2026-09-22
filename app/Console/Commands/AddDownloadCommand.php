<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DownloadsService;
use Illuminate\Console\Command;

/**
 * Enregistre un fichier volumineux (ZIP) déjà déposé sur le serveur (SCP) dans
 * les téléchargements privés : il est déplacé sous downloads/<token>/ et un lien
 * secret /dl/{token} est généré, à communiquer uniquement aux destinataires.
 */
final class AddDownloadCommand extends Command
{
    protected $signature = 'app:downloads:add {source : chemin absolu du fichier à enregistrer (déjà sur le serveur)}';

    protected $description = 'Enregistre un fichier volumineux dans les téléchargements privés et affiche le lien secret';

    public function handle(DownloadsService $downloads): int
    {
        $source = (string) $this->argument('source');

        if (! is_file($source)) {
            $this->error("Fichier introuvable : {$source}");

            return self::FAILURE;
        }

        try {
            $download = $downloads->register($source);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Fichier enregistré : '.$download['name'].' ('.DownloadsService::humanSize($download['size']).').');
        $this->info('Lien secret (à communiquer uniquement aux destinataires) :');
        $this->line('    '.$download['url']);
        $this->warn('Ne le diffuse que de manière privée : toute personne ayant ce lien peut télécharger.');

        return self::SUCCESS;
    }
}
