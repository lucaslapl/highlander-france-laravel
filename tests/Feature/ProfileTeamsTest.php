<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Affichage des équipes françaises actives sur les profils joueurs
 * (section « Équipe(s) FR actuelle(s) », alimentée par managed_teams).
 */
class ProfileTeamsTest extends TestCase
{
    use RefreshDatabase;

    private const PLAYER_STEAMID3 = '[U:1:424242]';

    private const PLAYER_STEAMID64 = '76561197960689970';

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite ne connaît pas FROM_UNIXTIME (utilisé par les requêtes MySQL du projet).
        DB::connection()->getPdo()->sqliteCreateFunction(
            'FROM_UNIXTIME',
            static fn (?int $value): ?string => $value === null ? null : date('Y-m-d H:i:s', $value)
        );
    }

    private function insertPlayer(): void
    {
        DB::table('players_info')->insert([
            'steamid' => self::PLAYER_STEAMID3,
            'name' => 'TestPlayer',
            'display_name' => 'TestPlayer',
            'avatar' => 'https://example.com/avatar.png',
        ]);
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
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function addMember(int $teamId, array $overrides = []): int
    {
        return (int) DB::table('managed_team_members')->insertGetId(array_merge([
            'team_id' => $teamId,
            'steamid64' => self::PLAYER_STEAMID64,
            'steam_name' => 'Joueur',
            'source' => 'manual',
            'status' => 'starter',
            'is_leader' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_le_profil_affiche_l_equipe_active_du_joueur(): void
    {
        $this->insertPlayer();
        $this->addMember($this->insertTeam(), ['class' => 'medic']);

        $response = $this->get('/profile/'.self::PLAYER_STEAMID64);

        $response->assertOk();
        $response->assertSee('Équipe(s) FR actuelle(s)');
        $response->assertSee('/equipes/france');
        $response->assertSee('France');
        $response->assertSee('Medic');
        $response->assertSee('Titulaire');
    }

    public function test_le_profil_marque_le_joueur_leader(): void
    {
        $this->insertPlayer();
        $this->addMember($this->insertTeam(['slug' => 'france']), ['is_leader' => 1]);

        $response = $this->get('/profile/'.self::PLAYER_STEAMID64);

        $response->assertOk();
        $response->assertSee('profile-team__leader');
        $response->assertSee("Leader de l'équipe", false);
    }

    public function test_le_profil_n_affiche_pas_les_equipes_inactives(): void
    {
        $this->insertPlayer();
        $this->addMember($this->insertTeam(['slug' => 'inactive', 'is_active' => 0]), ['status' => 'backup']);

        $response = $this->get('/profile/'.self::PLAYER_STEAMID64);

        $response->assertOk();
        $response->assertDontSee('Équipe(s) FR actuelle(s)');
        $response->assertDontSee('/equipes/inactive');
    }

    public function test_le_profil_sans_equipe_n_affiche_pas_la_section(): void
    {
        $this->insertPlayer();

        $response = $this->get('/profile/'.self::PLAYER_STEAMID64);

        $response->assertOk();
        $response->assertDontSee('Équipe(s) FR actuelle(s)');
    }

    public function test_le_profil_d_un_non_membre_n_affiche_pas_la_section(): void
    {
        $this->insertPlayer();
        $teamId = $this->insertTeam();

        DB::table('managed_team_members')->insert([
            'team_id' => $teamId,
            'steamid64' => '76561198999999999',
            'steam_name' => 'Autre joueur',
            'source' => 'manual',
            'status' => 'starter',
            'is_leader' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/profile/'.self::PLAYER_STEAMID64);

        $response->assertOk();
        $response->assertDontSee('Équipe(s) FR actuelle(s)');
    }

    public function test_les_equipes_sont_ordonnees_par_division_puis_par_nom(): void
    {
        $this->insertPlayer();
        $lowId = $this->insertTeam(['slug' => 'low', 'name' => 'Lowdown', 'division' => 'low']);
        $premId = $this->insertTeam(['slug' => 'prem', 'name' => 'Premiers', 'division' => 'prem']);
        $this->addMember($lowId);
        $this->addMember($premId);

        $response = $this->get('/profile/'.self::PLAYER_STEAMID64);

        $response->assertOk();
        $response->assertSeeInOrder(['/equipes/prem', '/equipes/low']);
    }
}
