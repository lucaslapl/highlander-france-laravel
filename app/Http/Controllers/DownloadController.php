<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DownloadsService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Téléchargements privés : sert un fichier volumineux à partir d'un token
 * secret présent dans l'URL (/dl/{token}). Aucun listing, aucune session :
 * seul le possesseur du lien peut télécharger.
 */
final class DownloadController extends Controller
{
    private DownloadsService $downloads;

    public function __construct()
    {
        $this->downloads = new DownloadsService;
    }

    public function show(string $token): Response
    {
        $file = $this->downloads->resolve($token);
        if ($file === null) {
            abort(404);
        }

        // Un transfert de plusieurs Go peut dépasser les limites d'exécution PHP ;
        // on lève la limite pour que le streaming aille au bout.
        @set_time_limit(0);

        $headers = [
            // Type générique : la MIME exacte serait devinée depuis le contenu
            // (finfo) et peut être fausse (ex. zip sans en-tête standard). Le
            // téléchargement forcé suffit grâce au Content-Disposition.
            'Content-Type' => 'application/octet-stream',
            // Lien secret : jamais mis en cache par les intermédiaires.
            'Cache-Control' => 'private, no-store, must-revalidate',
        ];

        if (config('hlfr.downloads.xsendfile', false)) {
            // Délégation à Apache mod_xsendfile : PHP ne fait que valider le token
            // puis confie l'envoi du fichier au serveur web.
            return new Response('', Response::HTTP_OK, array_merge($headers, [
                'X-Sendfile' => $file,
                'Content-Disposition' => 'attachment; filename="'.basename($file).'"',
                'Content-Length' => (string) filesize($file),
            ]));
        }

        // Streaming par PHP (BinaryFileResponse) avec $public=false : on garde la
        // main sur le Cache-Control (private, no-store). L'ETag est désactivé — son
        // calcul hasherait tout le fichier à chaque requête — mais le Last-Modified
        // (stat, gratuit) permet la reprise des téléchargements (Range/If-Range).
        return new BinaryFileResponse($file, Response::HTTP_OK, $headers, false, 'attachment', false, true);
    }
}
