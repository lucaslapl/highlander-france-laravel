<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Endpoint JSON du remplissage assisté « équipes ETF2L », partagé par tous
 * les outils overlay (Logs, Scores, Bracket, Pick/Ban) : interrogé en
 * JavaScript par admin_etf2l_teams.js derrière la boîte
 * admin/partials/etf2l_teams. Réservé aux admins et rôles caster / prod
 * (middleware overlay-tools), comme les éditeurs qu'il alimente. Les
 * réponses sont servies depuis le cache etf2l_api_cache : aucun appel HTTP
 * réel ici.
 */
class Etf2lTeamsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_endpoint_est_reserve_aux_roles_overlay(): void
    {
        $this->get('/admin/overlay/etf2l/teams?competition_id=42')->assertForbidden();
        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/overlay/etf2l/teams?competition_id=42')->assertForbidden();
    }

    public function test_un_caster_recupere_les_equipes_d_une_competition(): void
    {
        $this->seedCompetitionTeams(42);

        $response = $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/etf2l/teams?competition_id=42');

        $response->assertOk()
            ->assertJson(['teams' => [
                ['name' => 'Escouade 6', 'avatar' => 'https://example.com/e6.png', 'country' => 'Belgium'],
                ['name' => 'Les Baguettes', 'avatar' => 'https://example.com/lb.png', 'country' => 'France'],
            ]]);
    }

    public function test_un_membre_de_la_prod_recupere_les_equipes(): void
    {
        $this->seedCompetitionTeams(42);

        $this->withSession(['steamid' => '76561198012345678', 'is_producer' => true])
            ->get('/admin/overlay/etf2l/teams?competition_id=42')
            ->assertOk()
            ->assertJsonCount(2, 'teams');
    }

    public function test_une_competition_sans_parametre_est_rejetee(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/etf2l/teams')
            ->assertInvalid(['competition_id']);
    }

    /**
     * Amorce le cache des équipes d'une compétition (une page, deux
     * équipes) : le service n'a alors aucun appel HTTP à faire.
     */
    private function seedCompetitionTeams(int $competitionId): void
    {
        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api-v2.etf2l.org/competition/'.$competitionId.'/teams?limit=100&page=1',
            'payload' => json_encode([
                'teams' => [
                    'data' => [
                        ['id' => 1, 'name' => 'Les Baguettes', 'steam' => ['avatar' => 'https://example.com/lb.png'], 'country' => 'France'],
                        ['id' => 2, 'name' => 'Escouade 6', 'steam' => ['avatar' => 'https://example.com/e6.png'], 'country' => 'Belgium'],
                    ],
                    'last_page' => 1,
                ],
            ], JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ]);
    }

    // ─── Roster d'une équipe ─────────────────────────────────────────────

    public function test_le_endpoint_roster_est_reserve_aux_roles_overlay(): void
    {
        $this->get('/admin/overlay/etf2l/roster?team_id=21747')->assertForbidden();
        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/overlay/etf2l/roster?team_id=21747')->assertForbidden();
    }

    public function test_un_caster_recupere_le_roster_d_une_equipe(): void
    {
        $this->seedTeamRoster(21747, ['76561198000552896', '76561198052898676']);

        $response = $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/etf2l/roster?team_id=21747');

        $response->assertOk()
            ->assertJson(['roster' => [
                'id' => 21747,
                'name' => 'The Piece of Pie',
                'players' => ['76561198000552896', '76561198052898676'],
            ]]);
    }

    public function test_un_roster_sans_parametre_est_rejete(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/etf2l/roster')
            ->assertInvalid(['team_id']);
    }

    public function test_une_equipe_inconnue_renvoie_un_roster_null(): void
    {
        // Cache négatif amorcé pour l'équipe : aucun appel HTTP réel, le
        // client affiche son message de repli (saisie manuelle possible).
        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api-v2.etf2l.org/team/99999',
            'payload' => json_encode(['error' => 'hlfr_etf2l_indisponible'], JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ]);

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/etf2l/roster?team_id=99999')
            ->assertOk()
            ->assertJson(['roster' => null]);
    }

    /**
     * Amorce le cache ETF2L du roster d'une équipe (endpoint /team/{id}) :
     * le service n'a alors aucun appel HTTP à faire.
     *
     * @param  array<int, string>  $steamids64
     */
    private function seedTeamRoster(int $teamId, array $steamids64): void
    {
        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api-v2.etf2l.org/team/'.$teamId,
            'payload' => json_encode([
                'status' => ['code' => 200],
                'team' => [
                    'id' => $teamId,
                    'name' => 'The Piece of Pie',
                    'players' => array_map(
                        static fn (string $id64): array => ['name' => 'joueur', 'steam' => ['id64' => $id64]],
                        $steamids64
                    ),
                ],
            ], JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ]);
    }
}
