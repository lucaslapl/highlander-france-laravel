<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FaqRepository;
use App\Models\GuideRepository;
use App\Services\GuideMarkdown;
use Illuminate\Contracts\View\View;

final class GuideController extends Controller
{
    private const CATEGORIES = [
        'debuter' => 'Débuter',
        'highlander' => 'Highlander 9v9',
        'classes' => 'Classes',
        '6v6' => '6v6',
        'config' => 'Config',
    ];

    public function index(): View
    {
        $guides = (new GuideRepository)->published();

        return view('pages.guides-index', [
            'title' => 'Highlander France - Guides TF2 compétitif (Highlander 9v9 & 6v6)',
            'description' => 'Guides TF2 compétitif en français : débuter, Highlander 9v9, les 9 classes, 6v6 et config. Rédigés par Highlander France.',
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'Guides', 'url' => site_url().'/guides'],
            ],
            'guides' => $guides,
            'categories' => self::CATEGORIES,
        ]);
    }

    public function show(string $slug): View
    {
        $guide = (new GuideRepository)->findBySlug($slug);

        if ($guide === null || ! (int) $guide['is_published']) {
            abort(404);
        }

        $html = (new GuideMarkdown)->toHtml((string) $guide['content_markdown']);
        $guides = (new GuideRepository)->published();

        $prev = null;
        $next = null;
        foreach (array_values($guides) as $i => $g) {
            if ($g['slug'] === $slug) {
                $prev = $i > 0 ? $guides[$i - 1] : null;
                $next = $i < count($guides) - 1 ? $guides[$i + 1] : null;
                break;
            }
        }

        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $guide['title'],
            'description' => $guide['meta_description'],
            'inLanguage' => 'fr',
            'author' => ['@type' => 'Organization', 'name' => 'Highlander France', 'url' => site_url().'/'],
            'publisher' => ['@type' => 'Organization', 'name' => 'Highlander France', 'url' => site_url().'/'],
            'mainEntityOfPage' => site_url().'/guides/'.$guide['slug'],
        ];
        if (! empty($guide['updated_at'])) {
            $structuredData['dateModified'] = date('c', strtotime((string) $guide['updated_at']));
        }

        return view('pages.guide-show', [
            'title' => 'Highlander France - '.$guide['title'],
            'description' => $guide['meta_description'],
            'structuredData' => $structuredData,
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'Guides', 'url' => site_url().'/guides'],
                ['name' => $guide['title'], 'url' => site_url().'/guides/'.$guide['slug']],
            ],
            'guide' => $guide,
            'html' => $html,
            'excerpt' => $plain !== '' ? mb_substr($plain, 0, 200) : null,
            'prev' => $prev,
            'next' => $next,
            'guides' => $guides,
            'categoryLabel' => self::CATEGORIES[$guide['category']] ?? $guide['category'],
        ]);
    }

    public function faq(): View
    {
        $repo = new FaqRepository;
        $grouped = $repo->publishedByCluster();
        $md = new GuideMarkdown();

        $rendered = [];
        $entities = [];
        foreach ($grouped as $cluster => $items) {
            foreach ($items as $item) {
                $item['answer_html'] = $md->toHtml((string) $item['answer_markdown']);
                $rendered[$cluster][] = $item;
                $entities[] = [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(preg_replace('/\s+/', ' ', strip_tags($item['answer_html'])) ?? '')],
                ];
            }
        }

        return view('pages.faq', [
            'title' => 'Highlander France - FAQ TF2 compétitif : Highlander, équipe, ETF2L',
            'description' => 'FAQ TF2 compétitif : c’est quoi le Highlander 9v9, comment trouver une équipe, progresser, rejoindre l’ETF2L. Réponses par Highlander France.',
            'structuredData' => [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'inLanguage' => 'fr',
                'mainEntity' => $entities,
            ],
            'breadcrumbs' => [
                ['name' => 'Accueil', 'url' => site_url().'/'],
                ['name' => 'FAQ', 'url' => site_url().'/faq'],
            ],
            'grouped' => $rendered,
            'clusters' => FaqRepository::CLUSTERS,
        ]);
    }
}
