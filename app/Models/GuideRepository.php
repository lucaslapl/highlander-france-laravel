<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class GuideRepository
{
    public function published(): array
    {
        return DB::table('guides')
            ->where('is_published', 1)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public function findBySlug(string $slug): ?array
    {
        $row = DB::table('guides')->where('slug', $slug)->first();

        return $row !== null ? (array) $row : null;
    }

    public function find(int $id): ?array
    {
        $row = DB::table('guides')->where('id', $id)->first();

        return $row !== null ? (array) $row : null;
    }

    public function all(): array
    {
        return DB::table('guides')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $q = DB::table('guides')->where('slug', $slug);
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }

        return $q->exists();
    }

    public function create(array $data): int
    {
        $id = (int) DB::table('guides')->insertGetId([
            'slug' => $data['slug'],
            'title' => $data['title'],
            'meta_description' => $data['meta_description'],
            'category' => $data['category'],
            'excerpt' => $data['excerpt'] ?? null,
            'content_markdown' => $data['content_markdown'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_published' => ! empty($data['is_published']) ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::forget('sitemap');

        return $id;
    }

    public function update(int $id, array $data): void
    {
        DB::table('guides')->where('id', $id)->update([
            'slug' => $data['slug'],
            'title' => $data['title'],
            'meta_description' => $data['meta_description'],
            'category' => $data['category'],
            'excerpt' => $data['excerpt'] ?? null,
            'content_markdown' => $data['content_markdown'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_published' => ! empty($data['is_published']) ? 1 : 0,
            'updated_at' => now(),
        ]);
        Cache::forget('sitemap');
    }

    public function delete(int $id): void
    {
        DB::table('guides')->where('id', $id)->delete();
        Cache::forget('sitemap');
    }
}
