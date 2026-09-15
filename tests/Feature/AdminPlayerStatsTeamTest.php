<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Couverture des routes admin de l'onglet « Équipe » (préparation, recherche).
 */
class AdminPlayerStatsTeamTest extends TestCase
{
    use RefreshDatabase;

    private function adminSession(): array
    {
        return ['steamid' => '76561198012345678', 'is_admin' => true];
    }

    public function test_search_teams_est_reserve_aux_admins(): void
    {
        $this->get('/admin/stats-joueur/teams/search?q=fr')->assertForbidden();
    }

    public function test_search_teams_filtre_par_nom_et_tag(): void
    {
        DB::table('etf2l_teams')->insert([
            ['team_id' => 15176, 'name' => 'France', 'tag' => 'FRANCE', 'country' => 'france'],
            ['team_id' => 37618, 'name' => 'France CS Major', 'tag' => 'FCM', 'country' => 'france'],
            ['team_id' => 99999, 'name' => 'Other CLub', 'tag' => 'OC', 'country' => 'germany'],
        ]);

        $this->withSession($this->adminSession())
            ->get('/admin/stats-joueur/teams/search?q=france')
            ->assertOk()
            ->assertJson([
                ['id' => 15176, 'name' => 'France', 'tag' => 'FRANCE', 'country' => 'france'],
                ['id' => 37618, 'name' => 'France CS Major', 'tag' => 'FCM', 'country' => 'france'],
            ]);
    }

    public function test_prepare_team_est_reserve_aux_admins(): void
    {
        $this->post('/admin/stats-joueur/equipe/prepare', ['team' => 15176])->assertForbidden();
    }

    public function test_prepare_team_valide_l_id(): void
    {
        // Non admin bloqué ; admin avec ID invalide => 422 (validation avant tout appel réseau).
        $this->withSession($this->adminSession())
            ->postJson('/admin/stats-joueur/equipe/prepare', ['team' => 0])
            ->assertStatus(422);
    }

    public function test_start_team_est_reserve_aux_admins(): void
    {
        $this->post('/admin/stats-joueur/equipe/start', [])->assertForbidden();
    }
}
