<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gestion admin des équipes FR (managed_teams) : accès réservé, activation,
 * division, roster et désignation des leaders.
 */
class AdminManagedTeamTest extends TestCase
{
    use RefreshDatabase;

    private function adminSession(): array
    {
        return ['steamid' => '76561198012345678', 'is_admin' => true];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertTeam(array $overrides = []): int
    {
        return (int) DB::table('managed_teams')->insertGetId(array_merge([
            'slug' => 'france',
            'name' => 'France',
            'tag' => 'FRANCE',
            'country' => 'france',
            'etf2l_team_id' => 15176,
            'division' => 'high',
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function addMember(int $teamId, string $steamid64): int
    {
        return (int) DB::table('managed_team_members')->insertGetId([
            'team_id' => $teamId,
            'steamid64' => $steamid64,
            'steam_name' => 'Joueur',
            'source' => 'etf2l',
            'status' => 'starter',
            'is_leader' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── Accès ────────────────────────────────────────────────────────────

    public function test_l_index_est_reserve_aux_admins(): void
    {
        $this->get('/admin/equipes')->assertForbidden();
    }

    public function test_l_index_liste_les_equipes_importees(): void
    {
        $this->insertTeam();

        $this->withSession($this->adminSession())
            ->get('/admin/equipes')
            ->assertOk()
            ->assertSee('France');
    }

    public function test_le_store_est_reserve_aux_admins(): void
    {
        $this->post('/admin/equipes/store', ['etf2l_team_id' => 15176])->assertForbidden();
    }

    public function test_le_store_valide_l_id_etf2l(): void
    {
        $this->withSession($this->adminSession())
            ->postJson('/admin/equipes/store', ['etf2l_team_id' => 0])
            ->assertStatus(422);
    }

    // ─── Édition ──────────────────────────────────────────────────────────

    public function test_update_enregistre_la_division_et_l_activation(): void
    {
        $teamId = $this->insertTeam();

        $this->withSession($this->adminSession())
            ->post('/admin/equipes/'.$teamId.'/update', [
                'name' => 'France',
                'division' => 'prem',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $team = DB::table('managed_teams')->where('id', $teamId)->first();
        $this->assertSame('prem', $team->division);
        $this->assertSame(1, (int) $team->is_active);
    }

    public function test_update_conserve_l_activation_si_absent_du_formulaire(): void
    {
        $teamId = $this->insertTeam(['is_active' => 1]);

        $this->withSession($this->adminSession())
            ->post('/admin/equipes/'.$teamId.'/update', [
                'name' => 'France',
                'slogan' => 'Nouveau slogan',
            ])
            ->assertRedirect();

        $this->assertSame(1, (int) DB::table('managed_teams')->where('id', $teamId)->value('is_active'));
        $this->assertSame('Nouveau slogan', DB::table('managed_teams')->where('id', $teamId)->value('slogan'));
    }

    public function test_toggle_bascule_l_activation(): void
    {
        $teamId = $this->insertTeam(['is_active' => 1]);

        $this->withSession($this->adminSession())
            ->post('/admin/equipes/'.$teamId.'/toggle')
            ->assertRedirect();

        $this->assertSame(0, (int) DB::table('managed_teams')->where('id', $teamId)->value('is_active'));
    }

    // ─── Roster ───────────────────────────────────────────────────────────

    public function test_member_add_ajoute_un_joueur(): void
    {
        $teamId = $this->insertTeam();

        $this->withSession($this->adminSession())
            ->post('/admin/equipes/'.$teamId.'/members/add', [
                'steamid64' => '76561198000000042',
                'class' => 'soldier',
                'status' => 'backup',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('managed_team_members', [
            'team_id' => $teamId,
            'steamid64' => '76561198000000042',
            'class' => 'soldier',
            'status' => 'backup',
            'source' => 'manual',
        ]);
    }

    public function test_member_leader_promeut_puis_destitue(): void
    {
        $teamId = $this->insertTeam();
        $memberId = $this->addMember($teamId, '76561198000000042');

        $this->withSession($this->adminSession())
            ->post('/admin/equipes/'.$teamId.'/members/'.$memberId.'/leader')
            ->assertRedirect();
        $this->assertSame(1, (int) DB::table('managed_team_members')->where('id', $memberId)->value('is_leader'));

        $this->withSession($this->adminSession())
            ->post('/admin/equipes/'.$teamId.'/members/'.$memberId.'/leader')
            ->assertRedirect();
        $this->assertSame(0, (int) DB::table('managed_team_members')->where('id', $memberId)->value('is_leader'));
    }

    public function test_member_remove_retire_le_joueur(): void
    {
        $teamId = $this->insertTeam();
        $memberId = $this->addMember($teamId, '76561198000000042');

        $this->withSession($this->adminSession())
            ->post('/admin/equipes/'.$teamId.'/members/'.$memberId.'/remove')
            ->assertRedirect();

        $this->assertDatabaseMissing('managed_team_members', ['id' => $memberId]);
    }

    public function test_le_bouton_global_enregistre_roster_et_leaders(): void
    {
        $teamId = $this->insertTeam();
        $demomanId = $this->addMember($teamId, '76561198000000042');
        $engineerId = $this->addMember($teamId, '76561198000000043');

        $this->withSession($this->adminSession())
            ->post('/admin/equipes/'.$teamId.'/update', [
                'name' => 'France',
                'members' => [
                    $demomanId => ['class' => 'demoman', 'status' => 'backup'],
                    $engineerId => ['class' => 'engineer', 'status' => 'starter', 'is_leader' => '1'],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('managed_team_members', [
            'id' => $demomanId,
            'class' => 'demoman',
            'status' => 'backup',
            'is_leader' => 0,
        ]);
        $this->assertDatabaseHas('managed_team_members', [
            'id' => $engineerId,
            'class' => 'engineer',
            'status' => 'starter',
            'is_leader' => 1,
        ]);
    }

    public function test_le_bouton_global_ignore_les_membres_etrangers(): void
    {
        $teamId = $this->insertTeam();

        $this->withSession($this->adminSession())
            ->post('/admin/equipes/'.$teamId.'/update', [
                'name' => 'France',
                'members' => [
                    99999 => ['class' => 'demoman', 'status' => 'backup', 'is_leader' => '1'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(
            DB::table('managed_team_members')->where('team_id', $teamId)->count(),
            0
        );
    }
}
