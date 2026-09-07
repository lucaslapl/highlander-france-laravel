<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etf2lMapRepository;
use App\Services\Auth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Gestion des maps ETF2L (upload .bsp + miniatures) depuis le panel admin.
 *
 * Upload des .bsp :
 *  - drag & drop : le fichier est d'abord déposé (POST /admin/maps/upload-pending)
 *    dans un dossier privé d'attente avec barre de progression, puis "commité"
 *    (déplacé vers le dossier public) à la validation du formulaire.
 *  - sélection simple : l'upload direct (champ file) passe aussi par store().
 *  Cela évite de dépasser le post_max_size de l'hébergeur en n'envoyant qu'un
 *  seul .bsp à la fois.
 */
final class AdminMapController extends Controller
{
    private const MAX_BSP_KB = 102400;  // 100 Mo
    private const MAX_THUMB_KB = 10240; // 10 Mo

    private const PENDING_FOLDER = 'etf2l-maps-pending';
    private const PUBLIC_BASE = 'etf2l-maps';

    private Etf2lMapRepository $repo;

    public function __construct()
    {
        $this->repo = new Etf2lMapRepository();
    }

    /**
     * GET /admin/maps
     */
    public function index(): View
    {
        Auth::requireAdmin();

        $enrich = function (array $maps): array {
            foreach ($maps as &$map) {
                $map = $this->enrich($map);
            }
            unset($map);

            return $maps;
        };

        return view('admin.maps', [
            'title' => 'Admin - Gestion des maps ETF2L',
            'description' => 'Upload et gestion des maps ETF2L du site.',
            'styles' => ['/_css/admin.css'],
            'scripts' => ['/_js/admin_maps.js'],
            'maps6v6' => $enrich($this->repo->allByCategory()['6v6']),
            'maps9v9' => $enrich($this->repo->allByCategory()['9v9']),
        ]);
    }

    /**
     * POST /admin/maps/upload-pending — dépôt d'un .bsp (drag & drop) dans le dossier
     * privé d'attente. Renvoie le nom du fichier à commiter à la soumission du formulaire.
     */
    public function uploadPending(Request $request): JsonResponse
    {
        Auth::requireAdmin();

        $request->validate(['bsp' => ['required', 'file']]);
        $file = $request->file('bsp');

        $original = (string) $file->getClientOriginalName();
        $name = strtolower((string) pathinfo($original, PATHINFO_FILENAME));
        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            $name = 'map_' . time();
        }
        if (strtolower((string) pathinfo($original, PATHINFO_EXTENSION)) !== 'bsp') {
            return response()->json(['success' => false, 'message' => 'Le fichier doit être un .bsp.'], 422);
        }
        if ($file->getSize() > self::MAX_BSP_KB * 1024) {
            return response()->json(['success' => false, 'message' => 'Le fichier dépasse la taille maximale (100 Mo).'], 413);
        }

        $disk = Storage::disk('local');
        $rel = self::PENDING_FOLDER . '/' . $name . '.bsp';
        $disk->putFileAs(self::PENDING_FOLDER, $file, $name . '.bsp');

        return response()->json(['success' => true, 'name' => $name]);
    }

    /**
     * POST /admin/maps/store — ajout d'une map (AJAX multipart ou formulaire).
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Auth::requireAdmin();

        $data = $this->validateMap($request, null);
        $name = $data['name'];
        $category = $data['category'];

        $bspFile = null;
        if ($request->hasFile('bsp')) {
            // Sélection simple : upload direct vers le dossier public.
            $bspFile = $this->storeBspDirect($request, $name, $category);
        } elseif (!empty($data['bsp_pending'])) {
            // Drag & drop : déplacement du fichier en attente vers le dossier public.
            $bspFile = $this->commitPending($data['bsp_pending'], $name, $category);
            if ($bspFile === null) {
                return $this->respond($request, false, 'Le fichier .bsp déposé est introuvable (réessayez le drag & drop).');
            }
        }

        $thumbnail = $this->handleThumbnail($request, $name);

        $id = $this->repo->create([
            'name' => $name,
            'label' => $data['label'],
            'category' => $category,
            'bsp_file' => $bspFile,
            'thumbnail' => $thumbnail,
            'is_active' => $data['is_active'],
            'sort_order' => $this->repo->maxSortOrder($category) + 1,
        ]);

        return $this->respond($request, true, "La map « {$data['label']} » a été ajoutée avec succès.");
    }

    /**
     * POST /admin/maps/{id}/update — édition d'une map (dont remplacement de la miniature).
     */
    public function update(Request $request, int $id): JsonResponse|RedirectResponse
    {
        Auth::requireAdmin();

        $map = $this->repo->find($id);
        if ($map === null) {
            return $this->respond($request, false, 'Map introuvable.');
        }

        $data = $this->validateMap($request, $id, (string) $map['name']);
        $payload = [
            'label' => $data['label'],
            'category' => $data['category'],
            'is_active' => $data['is_active'],
        ];

        if ($request->hasFile('thumbnail')) {
            $newThumb = $this->handleThumbnail($request, (string) $map['name']);
            if ($newThumb !== null) {
                $this->deleteFile($map['thumbnail'] ?? null);
                $payload['thumbnail'] = $newThumb;
            }
        }

        $this->repo->update($id, $payload);

        return $this->respond($request, true, "La map « {$data['label']} » a été mise à jour.");
    }

    /**
     * POST /admin/maps/{id}/delete
     */
    public function delete(Request $request, int $id): JsonResponse|RedirectResponse
    {
        Auth::requireAdmin();

        $map = $this->repo->find($id);
        if ($map === null) {
            return $this->respond($request, false, 'Map introuvable.');
        }

        $this->deleteMapFiles($map);
        $this->repo->delete($id);

        return $this->respond($request, true, "La map « {$map['label']} » a été supprimée.");
    }

    /**
     * POST /admin/maps/reorder — réordonnancement d'une catégorie.
     */
    public function reorder(Request $request): JsonResponse|RedirectResponse
    {
        Auth::requireAdmin();

        $category = (string) $request->input('category', '');
        $ids = $request->input('ids', []);

        if (! in_array($category, ['6v6', '9v9'], true) || ! is_array($ids)) {
            return $this->respond($request, false, 'Paramètres invalides.');
        }

        $this->repo->reorder($category, array_map('intval', $ids));

        return $this->respond($request, true, 'Ordre mis à jour.');
    }

    /**
     * POST /admin/maps/{id}/toggle — passer une map active/inactive.
     */
    public function toggle(Request $request, int $id): JsonResponse|RedirectResponse
    {
        Auth::requireAdmin();

        $map = $this->repo->find($id);
        if ($map === null) {
            return $this->respond($request, false, 'Map introuvable.');
        }

        $this->repo->update($id, [
            'label' => $map['label'],
            'category' => $map['category'],
            'is_active' => !(int) $map['is_active'],
        ]);

        return $this->respond($request, true, 'Statut mis à jour.');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Validation des champs d'une map.
     *
     * @return array{name: string, label: string, category: string, is_active: bool, bsp_pending?: string}
     */
    private function validateMap(Request $request, ?int $id, string $existingName = ''): array
    {
        $nameRules = ['string', 'regex:/^[a-z0-9_]+$/', 'max:128'];
        if ($existingName === '') {
            array_unshift($nameRules, 'required');
        }

        $data = $request->validate([
            'name' => $nameRules,
            'label' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(['6v6', '9v9'])],
            'is_active' => ['sometimes', 'boolean'],
            'bsp_pending' => ['nullable', 'string', 'max:191'],
        ]);

        $name = $existingName !== '' ? $existingName : ($data['name'] ?? '');
        $category = $data['category'] ?? '6v6';
        if ($this->repo->nameExists($name, $category, $id)) {
            abort(422, "La map « {$name} » existe déjà dans cette catégorie.");
        }

        if ($existingName === '') {
            $data['name'] = $name;
        }
        $data['is_active'] = !empty($data['is_active']) && $data['is_active'] !== '0';

        return $data;
    }

    /**
     * Upload direct d'un .bsp (input file) vers le dossier public.
     * @return string|null nom du fichier stocké
     */
    private function storeBspDirect(Request $request, string $name, string $category): ?string
    {
        $request->validate(['bsp' => ['file', 'max:' . self::MAX_BSP_KB]]);
        $file = $request->file('bsp');
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'bsp') {
            return null;
        }

        $relPath = self::PUBLIC_BASE . '/' . $category . '/' . $name . '.bsp';
        $abs = storage_path('app/public/' . $relPath);
        if (! is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0755, true);
        }
        $stream = fopen((string) $file->getRealPath(), 'rb');
        $out = fopen($abs, 'wb');
        if ($stream === false || $out === false) {
            return null;
        }
        while (! feof($stream)) {
            fwrite($out, (string) fread($stream, 1048576));
        }
        fclose($out);
        fclose($stream);

        return $name . '.bsp';
    }

    /**
     * Déplace un fichier .bsp mis en attente (drag & drop) vers le dossier public.
     * @return string|null nom du fichier stocké
     */
    private function commitPending(string $pendingName, string $name, string $category): ?string
    {
        $tmpRel = self::PENDING_FOLDER . '/' . basename($pendingName) . '.bsp';
        $tmpAbs = storage_path('app/private/' . $tmpRel);
        if (! is_file($tmpAbs)) {
            // 2e tentative : éventuel .bsp sans suffixe (robustesse).
            $tmpAbs = storage_path('app/private/' . self::PENDING_FOLDER . '/' . basename($pendingName));
            if (! is_file($tmpAbs)) {
                return null;
            }
        }

        $relPath = self::PUBLIC_BASE . '/' . $category . '/' . $name . '.bsp';
        $abs = storage_path('app/public/' . $relPath);
        if (! is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0755, true);
        }
        @rename($tmpAbs, $abs);

        return is_file($abs) ? $name . '.bsp' : null;
    }

    /**
     * Miniatura : stocke l'upload et renvoie le chemin public relatif, sinon null.
     */
    private function handleThumbnail(Request $request, string $name): ?string
    {
        if (! $request->hasFile('thumbnail')) {
            return null;
        }

        $request->validate(['thumbnail' => ['image', 'mimes:jpeg,png,webp', 'max:' . self::MAX_THUMB_KB]]);
        $file = $request->file('thumbnail');
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'png';
        }

        $relPath = self::PUBLIC_BASE . '/thumbnails/' . $name . '.' . $ext;
        $abs = storage_path('app/public/' . $relPath);
        if (! is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0755, true);
        }
        move_uploaded_file((string) $file->getRealPath(), $abs)
            ?: @copy((string) $file->getRealPath(), $abs);

        return $relPath;
    }

    private function deleteFile(?string $relPath): void
    {
        if ($relPath === null || $relPath === '') {
            return;
        }
        $abs = storage_path('app/public/' . $relPath);
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    private function deleteMapFiles(array $map): void
    {
        $bsp = $map['bsp_file'] ?? null;
        if ($bsp !== null) {
            $abs = storage_path('app/public/' . self::PUBLIC_BASE . '/' . $map['category'] . '/' . $bsp);
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $this->deleteFile($map['thumbnail'] ?? null);
    }

    /**
     * Ajoute les infos utiles à l'affichage admin d'une map (thumb URL, taille...).
     *
     * @return array<string, mixed>
     */
    private function enrich(array $map): array
    {
        $map['thumb_url'] = null;
        if (!empty($map['thumbnail'])) {
            $map['thumb_url'] = asset('storage/' . ltrim((string) $map['thumbnail'], '/'));
        }

        $map['bsp_url'] = null;
        $map['bsp_exists'] = false;
        $map['bsp_size_human'] = null;
        if (!empty($map['bsp_file'])) {
            $abs = storage_path('app/public/' . self::PUBLIC_BASE . '/' . $map['category'] . '/' . $map['bsp_file']);
            if (is_file($abs)) {
                $map['bsp_exists'] = true;
                $map['bsp_url'] = asset('storage/' . self::PUBLIC_BASE . '/' . $map['category'] . '/' . $map['bsp_file']);
                $map['bsp_size_human'] = number_format(filesize($abs) / 1048576, 1) . ' Mo';
            }
        }

        return $map;
    }

    private function respond(Request $request, bool $success, string $message): JsonResponse|RedirectResponse
    {
        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['success' => $success, 'message' => $message]);
        }

        return redirect('/admin/maps')->with($success ? 'success' : 'error', $message);
    }
}
