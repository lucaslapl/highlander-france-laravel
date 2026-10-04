<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class HlfrThemeTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['hlfr.theme' => null]);

        parent::tearDown();
    }

    public function test_retourne_default_sans_configuration(): void
    {
        config(['hlfr.theme' => null]);

        $this->assertSame('default', hlfr_theme());
    }

    public function test_retourne_un_theme_installe(): void
    {
        // halloween.css est livré avec le site : il doit être reconnu.
        config(['hlfr.theme' => 'halloween']);

        $this->assertSame('halloween', hlfr_theme());
    }

    public function test_normalise_casse_et_espaces(): void
    {
        config(['hlfr.theme' => '  Halloween ']);

        $this->assertSame('halloween', hlfr_theme());
    }

    public function test_retombe_sur_default_pour_un_theme_introuvable(): void
    {
        config(['hlfr.theme' => 'inexistant']);

        $this->assertSame('default', hlfr_theme());
    }

    public function test_retombe_sur_default_pour_un_nom_invalide(): void
    {
        // N'importe quel caractère hors [a-z0-9-] doit être rejeté
        // (protection contre une injection de chemin via la config).
        config(['hlfr.theme' => '../noel']);
        $this->assertSame('default', hlfr_theme());

        config(['hlfr.theme' => 'noel.css']);
        $this->assertSame('default', hlfr_theme());
    }
}
