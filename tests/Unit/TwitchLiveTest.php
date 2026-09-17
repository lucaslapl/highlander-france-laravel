<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TwitchLive;
use Tests\TestCase;

class TwitchLiveTest extends TestCase
{
    public function test_merge_simulated_conserve_les_entrees_simulees(): void
    {
        $previous = [
            ['login' => 'highlanderfrance', 'simulated' => true, 'title' => 'Simulé A'],
            ['login' => 'autre_chaine', 'simulated' => true, 'title' => 'Simulé B'],
            ['login' => 'highlanderfrance', 'title' => 'Réel obsolète (écrasé par le refresh)'],
        ];

        $live = [
            ['login' => 'highlanderfrance', 'title' => 'Réel en direct', 'viewers' => 12],
        ];

        $merged = TwitchLive::mergeSimulated($previous, $live);

        // Les réelles priment sur les simulées du même login, les autres
        // simulées survivent au refresh (elles restent jusqu'au reset admin).
        $this->assertSame(
            ['highlanderfrance', 'autre_chaine'],
            array_map(static fn (array $ch): string => (string) $ch['login'], $merged),
        );
        $this->assertSame('Réel en direct', $merged[0]['title']);
        $this->assertSame('Simulé B', $merged[1]['title']);
    }

    public function test_merge_simulated_sans_entree_simulee(): void
    {
        $previous = [
            ['login' => 'highlanderfrance', 'title' => 'Réel obsolète'],
        ];
        $live = [
            ['login' => 'highlanderfrance', 'title' => 'Réel en direct'],
        ];

        $this->assertSame($live, TwitchLive::mergeSimulated($previous, $live));
    }

    public function test_merge_simulated_sans_entree_reelle(): void
    {
        $previous = [
            ['login' => 'highlanderfrance', 'simulated' => true, 'title' => 'Simulé A'],
        ];

        $this->assertSame($previous, TwitchLive::mergeSimulated($previous, []));
    }

    public function test_build_sidebar_met_en_avant_hl_france(): void
    {
        $priority = [
            ['login' => 'hlfrcaster', 'display_name' => 'Caster HLFR', 'title' => 'Live HLFR', 'viewers' => 3],
        ];

        $french = [
            ['login' => 'autre_fr', 'display_name' => 'Autre FR', 'title' => 'Scrim', 'viewers' => 50],
            ['login' => 'hlfrcaster', 'display_name' => 'Caster HLFR (dupliqué)', 'title' => 'Dédoublonné', 'viewers' => 999],
        ];

        $sidebar = TwitchLive::buildSidebar($priority, $french);

        // HL France en tête (mêlé au tri par viewers), le doublon Helix écarté.
        $this->assertSame(['hlfrcaster', 'autre_fr'], array_column($sidebar, 'login'));
        $this->assertTrue($sidebar[0]['hl']);
        $this->assertFalse($sidebar[1]['hl']);
        $this->assertSame('Live HLFR', $sidebar[0]['title']);
    }

    public function test_build_sidebar_trie_par_viewers_et_plafonne(): void
    {
        $priority = [
            ['login' => 'p2', 'display_name' => 'P2', 'title' => 't', 'viewers' => 2],
            ['login' => 'p1', 'display_name' => 'P1', 'title' => 't', 'viewers' => 9],
        ];
        $french = [
            ['login' => 'f3', 'display_name' => 'F3', 'title' => 't', 'viewers' => 1],
            ['login' => 'f9', 'display_name' => 'F9', 'title' => 't', 'viewers' => 4],
        ];

        $sidebar = TwitchLive::buildSidebar($priority, $french, 3);

        $this->assertSame(['p1', 'p2', 'f9'], array_column($sidebar, 'login'));
        // Cap appliqué après fusion des deux groupes.
        $this->assertCount(3, $sidebar);
        $this->assertTrue($sidebar[0]['hl']);
        $this->assertTrue($sidebar[1]['hl']);
        $this->assertFalse($sidebar[2]['hl']);
    }

    public function test_build_sidebar_entrees_vides(): void
    {
        $this->assertSame([], TwitchLive::buildSidebar([], []));
    }

    public function test_build_sidebar_integre_les_entrees_simulees(): void
    {
        $previous = [
            ['login' => 'hlcast', 'display_name' => 'HL Cast', 'title' => 'Live', 'viewers' => 1, 'simulated' => true],
        ];

        // Reproduit la ligne doRefresh : la liste HL France est enrichie des
        // chaînes simulées survivantes (le simulateur admin reste utilisable
        // pour prévisualiser l'encadré).
        $sidebar = TwitchLive::buildSidebar(TwitchLive::mergeSimulated($previous, []), []);

        $this->assertCount(1, $sidebar);
        $this->assertSame('hlcast', $sidebar[0]['login']);
        $this->assertTrue($sidebar[0]['hl']);
    }
}
