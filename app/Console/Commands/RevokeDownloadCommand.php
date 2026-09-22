<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DownloadsService;
use Illuminate\Console\Command;

/**
 * Révoque un téléchargement privé : supprime le fichier et son token secret.
 * Le lien /dl/{token} cesse alors de fonctionner.
 */
final class RevokeDownloadCommand extends Command
{
    protected $signature = 'app:downloads:revoke {token : token secret du téléchargement à révoquer}';

    protected $description = 'Révoque un téléchargement privé (supprime le fichier et le lien)';

    public function handle(DownloadsService $downloads): int
    {
        $token = strtolower((string) $this->argument('token'));

        if (preg_match(DownloadsService::TOKEN_REGEX, $token) !== 1) {
            $this->error('Token invalide (32 caractères hexadécimaux attendus).');

            return self::FAILURE;
        }

        if (! $downloads->revoke($token)) {
            $this->error("Aucun téléchargement enregistré pour le token {$token}.");

            return self::FAILURE;
        }

        $this->info("Téléchargement révoqué : le lien /dl/{$token} ne fonctionne plus.");

        return self::SUCCESS;
    }
}
