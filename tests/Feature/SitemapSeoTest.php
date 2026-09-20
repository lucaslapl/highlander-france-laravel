<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sitemap XML : les pages piliers (dont /equipes) doivent être exposées.
 */
class SitemapSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_sitemap_expose_les_pages_piliers_dont_equipes(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $content = (string) $response->getContent();

        $this->assertStringContainsString('<urlset', $content);
        $this->assertStringContainsString('/equipes</loc>', $content);
        $this->assertStringContainsString('/matchs</loc>', $content);
        $this->assertStringContainsString('/guides</loc>', $content);
    }
}
