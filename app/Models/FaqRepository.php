<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class FaqRepository
{
    public const CLUSTERS = [
        'decouverte' => 'Découverte',
        'highlander' => 'Highlander',
        'recrutement' => 'Recrutement',
        'apprentissage' => 'Apprentissage',
        'competition' => 'Compétition',
    ];

    public function publishedByCluster(): array
    {
        $rows = DB::table('faq_items')
            ->where('is_published', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        $grouped = [];
        foreach (array_keys(self::CLUSTERS) as $cluster) {
            $grouped[$cluster] = [];
        }
        foreach ($rows as $row) {
            $grouped[$row['cluster']][] = $row;
        }

        return $grouped;
    }

    public function all(): array
    {
        return DB::table('faq_items')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public function find(int $id): ?array
    {
        $row = DB::table('faq_items')->where('id', $id)->first();

        return $row !== null ? (array) $row : null;
    }

    public function create(array $data): int
    {
        $id = (int) DB::table('faq_items')->insertGetId([
            'cluster' => $data['cluster'],
            'question' => $data['question'],
            'answer_markdown' => $data['answer_markdown'],
            'keywords' => $data['keywords'] ?? null,
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
        DB::table('faq_items')->where('id', $id)->update([
            'cluster' => $data['cluster'],
            'question' => $data['question'],
            'answer_markdown' => $data['answer_markdown'],
            'keywords' => $data['keywords'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_published' => ! empty($data['is_published']) ? 1 : 0,
            'updated_at' => now(),
        ]);
        Cache::forget('sitemap');
    }

    public function delete(int $id): void
    {
        DB::table('faq_items')->where('id', $id)->delete();
        Cache::forget('sitemap');
    }
}
