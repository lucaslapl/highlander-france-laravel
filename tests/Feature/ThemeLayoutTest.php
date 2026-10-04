<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ThemeLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // /joueurs exige une session et au moins un joueur en base.
        DB::table('players_info')->insert([
            'steamid' => '[U:1:42]',
            'name' => 'alpha',
            'display_name' => 'Alpha',
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        config(['hlfr.theme' => null]);

        parent::tearDown();
    }

    public function test_page_sans_theme_ni_charge_ni_classe(): void
    {
        config(['hlfr.theme' => null]);

        $response = $this->withSession(['steamid' => '76561197960265729'])->get('/joueurs');

        $response->assertStatus(200);
        $response->assertDontSee('class="theme-', false);
        $response->assertDontSee('/_css/themes/');
    }

    public function test_page_avec_theme_charge_le_css_et_pose_la_classe(): void
    {
        config(['hlfr.theme' => 'halloween']);

        $response = $this->withSession(['steamid' => '76561197960265729'])->get('/joueurs');

        $response->assertStatus(200);
        $response->assertSee('class="theme-halloween"', false);
        $response->assertSee('/_css/themes/halloween.css', false);
    }

    public function test_theme_introuvable_retombe_sur_le_theme_par_defaut(): void
    {
        config(['hlfr.theme' => 'fantome']);

        $response = $this->withSession(['steamid' => '76561197960265729'])->get('/joueurs');

        $response->assertStatus(200);
        $response->assertDontSee('class="theme-', false);
        $response->assertDontSee('/_css/themes/');
    }
}
