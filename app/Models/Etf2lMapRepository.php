<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Données des maps ETF2L gérées via le panel admin.
 */
final class Etf2lMapRepository
{
    /** Dossier de stockage relatif au disque public (lien `storage` → app/public). */
    private const STORAGE_REL = 'etf2l-maps';

    /**
     * Toutes les maps, groupées par catégorie et triées.
     *
     * @return array<string, array<int, array<string, mixed>>>  key = '6v6'|'9v9'
     */
    public function allByCategory(): array
    {
        $rows = DB::table('etf2l_maps')
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        $grouped = ['6v6' => [], '9v9' => []];
        foreach ($rows as $row) {
            $grouped[$row['category']][] = $row;
        }

        return $grouped;
    }

    /**
     * Maps actives destinées à la page publique.
     *
     * @return array<string, array<int, array<string, mixed>>>  key = '6v6'|'9v9'
     */
    public function activeByCategory(): array
    {
        $grouped = ['6v6' => [], '9v9' => []];

        foreach (['6v6', '9v9'] as $category) {
            $grouped[$category] = DB::table('etf2l_maps')
                ->where('category', $category)
                ->where('is_active', 1)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        }

        return $grouped;
    }

    public function find(int $id): ?array
    {
        $row = DB::table('etf2l_maps')->where('id', $id)->first();

        return $row !== null ? (array) $row : null;
    }

    public function nameExists(string $name, string $category, ?int $exceptId = null): bool
    {
        $q = DB::table('etf2l_maps')->where('name', $name)->where('category', $category);
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }

        return $q->exists();
    }

    public function create(array $data): int
    {
        $id = DB::table('etf2l_maps')->insertGetId([
            'name' => $data['name'],
            'label' => $data['label'],
            'category' => $data['category'],
            'sort_order' => $data['sort_order'] ?? 0,
            'bsp_file' => $data['bsp_file'] ?? null,
            'thumbnail' => $data['thumbnail'] ?? null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $id;
    }

    public function update(int $id, array $data): void
    {
        $payload = [
            'label' => $data['label'],
            'category' => $data['category'],
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_at' => now(),
        ];

        if (isset($data['name'])) {
            $payload['name'] = $data['name'];
        }
        if (isset($data['bsp_file'])) {
            $payload['bsp_file'] = $data['bsp_file'];
        }
        if (array_key_exists('thumbnail', $data)) {
            $payload['thumbnail'] = $data['thumbnail'];
        }

        DB::table('etf2l_maps')->where('id', $id)->update($payload);
    }

    public function delete(int $id): void
    {
        DB::table('etf2l_maps')->where('id', $id)->delete();
    }

    /**
     * Assignation d'un ordre de tri à partir d'une liste d'ids (catégorie donnée).
     *
     * @param int[] $orderedIds
     */
    public function reorder(string $category, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            DB::table('etf2l_maps')
                ->where('id', (int) $id)
                ->where('category', $category)
                ->update(['sort_order' => $index + 1, 'updated_at' => now()]);
        }
    }

    /**
     * Plus grand sort_order existant pour une catégorie (pour ajouter en fin de liste).
     */
    public function maxSortOrder(string $category): int
    {
        return (int) DB::table('etf2l_maps')
            ->where('category', $category)
            ->max('sort_order');
    }

    /**
     * Dossier public réel où les .bsp sont servis pour une catégorie.
     */
    public function bspStorageFolder(string $category): string
    {
        return storage_path('app/public/' . self::STORAGE_REL . '/' . $category);
    }
}
