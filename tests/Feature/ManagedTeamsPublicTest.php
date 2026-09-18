<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TeamStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pages publiques des équipes françaises : listing par division, fiche équipe
 * et espace d'édition réservé aux leaders.
 */
class ManagedTeamsPublicTest extends TestCase
{
    use RefreshDatabase;

    private const LEADER_STEAMID = '76561198012345678';

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
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function addMember(int $teamId, string $steamid64, bool $leader = false): int
    {
        return (int) DB::table('managed_team_members')->insertGetId([
            'team_id' => $teamId,
            'steamid64' => $steamid64,
            'steam_name' => 'Joueur',
            'source' => 'manual',
            'status' => 'starter',
            'is_leader' => $leader ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function bindEmptyStats(): void
    {
        $this->app->instance(TeamStatsService::class, new TeamStatsService(static fn (string $url): ?array => null));
    }

    // ─── Listing ─────────────────────────────────────────────────────────

    public function test_le_listing_ordonne_par_division_puis_par_nom(): void
    {
        $this->insertTeam(['slug' => 'zeta', 'name' => 'Zeta', 'division' => 'open']);
        $this->insertTeam(['slug' => 'alpha', 'name' => 'Alpha', 'division' => 'high']);
        $this->insertTeam(['slug' => 'prem', 'name' => 'Premiers', 'division' => 'prem']);
        $this->insertTeam(['slug' => 'low', 'name' => 'Lowdown', 'division' => 'low']);
        $this->insertTeam(['slug' => 'cachee', 'name' => 'Cachee', 'division' => 'high', 'is_active' => 0]);

        $response = $this->get('/equipes');

        $response->assertOk();
        $response->assertSeeInOrder(['/equipes/prem', '/equipes/alpha', '/equipes/low', '/equipes/zeta']);
        $response->assertDontSee('Cachee');
    }

    public function test_le_listing_affiche_le_badge_de_format(): void
    {
        $this->insertTeam(['slug' => 'sixes', 'name' => 'Sixes', 'format' => '6v6']);
        $this->insertTeam(['slug' => 'hl', 'name' => 'Highlander', 'format' => '9v9']);

        $response = $this->get('/equipes');

        $response->assertOk();
        $response->assertSee('team-format', false);
        $response->assertSeeInOrder(['Sixes', '6v6', 'Highlander', '9v9'], false);
    }

    // ─── Fiche équipe ────────────────────────────────────────────────────

    public function test_la_fiche_equipe_repond_404_pour_une_equipe_inconnue(): void
    {
        $this->bindEmptyStats();

        $this->get('/equipes/inexistante')->assertNotFound();
    }

    public function test_la_fiche_equipe_repond_404_pour_une_equipe_inactive(): void
    {
        $this->bindEmptyStats();
        $this->insertTeam(['slug' => 'cachee', 'is_active' => 0]);

        $this->get('/equipes/cachee')->assertNotFound();
    }

    public function test_la_fiche_equipe_affiche_le_roster(): void
    {
        $this->bindEmptyStats();
        $teamId = $this->insertTeam();
        $this->addMember($teamId, self::LEADER_STEAMID, true);

        $response = $this->get('/equipes/france');

        $response->assertOk();
        $response->assertSee('France');
        $response->assertSee('Joueur');
    }

    public function test_la_fiche_equipe_affiche_le_badge_de_format(): void
    {
        $this->bindEmptyStats();
        $this->insertTeam(['format' => '6v6']);

        $this->get('/equipes/france')->assertOk()->assertSee('6v6');
    }

    // ─── Permissions d'édition ────────────────────────────────────────────

    public function test_l_edition_est_interdite_aux_visiteurs(): void
    {
        $this->insertTeam();

        $this->get('/equipes/france/editer')->assertForbidden();
    }

    public function test_l_edition_est_interdite_aux_non_leaders(): void
    {
        $this->insertTeam();

        $this->withSession(['steamid' => '76561198999999999'])
            ->get('/equipes/france/editer')
            ->assertForbidden();
    }

    public function test_l_edition_est_ouverte_au_leader(): void
    {
        $teamId = $this->insertTeam();
        $this->addMember($teamId, self::LEADER_STEAMID, true);

        $this->withSession(['steamid' => self::LEADER_STEAMID])
            ->get('/equipes/france/editer')
            ->assertOk();
    }

    public function test_l_edition_est_ouverte_aux_admins(): void
    {
        $this->insertTeam();

        $this->withSession(['steamid' => '76561198999999999', 'is_admin' => true])
            ->get('/equipes/france/editer')
            ->assertOk();
    }

    // ─── Gestion du roster par le leader ─────────────────────────────────

    public function test_le_leader_peut_ajouter_un_membre(): void
    {
        $teamId = $this->insertTeam();
        $this->addMember($teamId, self::LEADER_STEAMID, true);

        $response = $this->withSession(['steamid' => self::LEADER_STEAMID])
            ->post('/equipes/france/membres/ajouter', [
                'steamid64' => '76561198000000010',
                'class' => 'scout',
                'status' => 'backup',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('managed_team_members', [
            'team_id' => $teamId,
            'steamid64' => '76561198000000010',
            'class' => 'scout',
            'status' => 'backup',
            'source' => 'manual',
        ]);
    }

    public function test_un_non_leader_ne_peut_pas_ajouter_de_membre(): void
    {
        $this->insertTeam();

        $this->withSession(['steamid' => '76561198999999999'])
            ->post('/equipes/france/membres/ajouter', ['steamid64' => '76561198000000010'])
            ->assertForbidden();
    }

    public function test_l_ajout_d_un_membre_valide_le_steamid(): void
    {
        $teamId = $this->insertTeam();
        $this->addMember($teamId, self::LEADER_STEAMID, true);

        $this->withSession(['steamid' => self::LEADER_STEAMID])
            ->post('/equipes/france/membres/ajouter', ['steamid64' => 'pas-un-steamid'])
            ->assertSessionHasErrors('steamid64');
    }

    public function test_le_leader_peut_modifier_la_presentation(): void
    {
        $teamId = $this->insertTeam();
        $this->addMember($teamId, self::LEADER_STEAMID, true);

        $this->withSession(['steamid' => self::LEADER_STEAMID])
            ->post('/equipes/france/editer', ['slogan' => 'Allez les bleus', 'description' => "# Titre\n\nMenu **complet**."])
            ->assertRedirect();

        $team = DB::table('managed_teams')->where('id', $teamId)->first();
        $this->assertSame('Allez les bleus', $team->slogan);
        $this->assertStringContainsString('Titre', (string) $team->description);
    }

    public function test_le_bouton_global_enregistre_presentation_et_roster(): void
    {
        $teamId = $this->insertTeam();
        $this->addMember($teamId, self::LEADER_STEAMID, true);
        $teammateId = $this->addMember($teamId, '76561198000000011');

        $this->withSession(['steamid' => self::LEADER_STEAMID])
            ->post('/equipes/france/editer', [
                'slogan' => 'Ne lâchez rien',
                'description' => 'Une équipe soudée.',
                'members' => [
                    $teammateId => ['class' => 'soldier', 'status' => 'backup'],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('managed_team_members', [
            'id' => $teammateId,
            'class' => 'soldier',
            'status' => 'backup',
        ]);
        $this->assertDatabaseHas('managed_teams', [
            'id' => $teamId,
            'slogan' => 'Ne lâchez rien',
        ]);
    }

    public function test_le_bouton_global_ignore_les_membres_etrangers(): void
    {
        $teamId = $this->insertTeam();
        $this->addMember($teamId, self::LEADER_STEAMID, true);
        $this->addMember($teamId, '76561198000000011');

        $this->withSession(['steamid' => self::LEADER_STEAMID])
            ->post('/equipes/france/editer', [
                'members' => [
                    99999 => ['class' => 'demoman', 'status' => 'backup'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(
            0,
            DB::table('managed_team_members')->where('team_id', $teamId)->where('id', 99999)->count()
        );
    }

    // ─── Logo (servi par la route dédiée, sans symlink storage) ───────────

    private function writeLogo(int $teamId): string
    {
        $dir = storage_path('app/public/team-logos/'.$teamId);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.'/logo.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));

        return $path;
    }

    public function test_la_route_logo_sert_le_fichier_disque(): void
    {
        $teamId = $this->insertTeam();
        $path = $this->writeLogo($teamId);

        try {
            $response = $this->get('/logo/team/'.$teamId.'/logo.png');

            $response->assertOk();
            $response->assertHeader('Content-Type', 'image/png');
            $response->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        } finally {
            @unlink($path);
        }
    }

    public function test_la_route_logo_refuse_les_fichiers_invalides(): void
    {
        $teamId = $this->insertTeam();

        $this->get('/logo/team/'.$teamId.'/logo.svg')->assertNotFound();
        $this->get('/logo/team/'.$teamId.'/introuvable.png')->assertNotFound();
        $this->get('/logo/team/'.$teamId.'/logo.png')->assertNotFound();
    }
}
