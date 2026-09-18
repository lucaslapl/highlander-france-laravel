<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ManagedTeamsRepository;
use Illuminate\Http\UploadedFile;

/**
 * Logique commune aux pages publiques et au panel admin des équipes gérées :
 * permissions d'édition (leader ou admin), logo (upload/suppression) et
 * enrichissement d'affichage (description Markdown, libellé de division).
 */
final class ManagedTeamService
{
    private const LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly ManagedTeamsRepository $teams,
    ) {}

    /**
     * Le visiteur connecté peut-il éditer cette équipe ? Admin, ou leader
     * figurant au roster de l'équipe.
     */
    public function canEdit(array $team): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }

        if (! Auth::isLoggedIn()) {
            return false;
        }

        $steamid64 = Auth::steamId64();
        if ($steamid64 === null) {
            return false;
        }

        return $this->teams->isTeamLeader(SteamId::toSteamId3($steamid64), (int) $team['id']);
    }

    /**
     * Enregistre le logo d'une équipe (remplace l'ancien). Retourne false si
     * l'extension n'est pas autorisée.
     */
    public function saveLogo(array $team, UploadedFile $file): bool
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (! in_array($ext, self::LOGO_EXTENSIONS, true)) {
            return false;
        }

        $this->deleteLogo($team);

        $relPath = 'team-logos/'.(int) $team['id'].'/logo.'.$ext;
        $abs = storage_path('app/public/'.$relPath);
        if (! is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0755, true);
        }
        move_uploaded_file((string) $file->getRealPath(), $abs)
            ?: @copy((string) $file->getRealPath(), $abs);

        $this->teams->update((int) $team['id'], ['logo_path' => $relPath, 'updated_at' => now()]);

        return true;
    }

    /**
     * Supprime le logo d'une équipe (fichiers + référence).
     */
    public function deleteLogo(array $team): void
    {
        $dir = storage_path('app/public/team-logos/'.(int) $team['id']);
        if (is_dir($dir)) {
            foreach (glob($dir.'/logo.*') ?: [] as $file) {
                @unlink($file);
            }
        }

        $this->teams->update((int) $team['id'], ['logo_path' => null, 'updated_at' => now()]);
    }

    /**
     * Enrichit une équipe pour l'affichage public (logo, HTML description,
     * libellé de division).
     *
     * @return array<string, mixed>
     */
    public function present(array $team): array
    {
        $team['logo_url'] = ManagedTeamsRepository::logoUrl($team['logo_path'] ?? null);
        $team['description_html'] = ! empty($team['description'])
            ? (new GuideMarkdown)->toHtml((string) $team['description'])
            : null;

        $divisions = (array) config('hlfr.team_divisions', []);
        $team['division_label'] = $divisions[$team['division'] ?? ''] ?? null;

        $formats = (array) config('hlfr.team_formats', []);
        $team['format_label'] = $formats[$team['format'] ?? ''] ?? null;

        return $team;
    }
}
