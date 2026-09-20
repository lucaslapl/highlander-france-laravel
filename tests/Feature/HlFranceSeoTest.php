<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verrouille les signaux SEO de l'abréviation « HL France ».
 */
class HlFranceSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_description_par_defaut_contient_le_synonyme_hl_france(): void
    {
        $this->assertStringContainsString('HL France', site_description());
        $this->assertStringContainsString('Highlander France', site_description());
    }

    public function test_l_accueil_expose_le_synonyme_hl_france(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $content = (string) $response->getContent();

        // Titre d'accueil inchangé + abréviation présente (meta description,
        // contenu visible, données structurées).
        $response->assertSee('Highlander France - Communauté Compétitive de TF2', false);
        $this->assertStringContainsString('HL France', $content);

        // Données structurées : Google apprend le synonyme.
        $this->assertStringContainsString('"alternateName"', $content);
        $this->assertStringContainsString('"HL France"', $content);
    }
}
