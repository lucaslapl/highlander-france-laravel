<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JoueursPageTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlayer(string $name, string $displayName, string $createdAt): void
    {
        DB::table('players_info')->insert([
            'steamid' => '[U:1:'.random_int(2, 999999).']',
            'name' => $name,
            'display_name' => $displayName,
            'created_at' => $createdAt,
        ]);
    }

    private function seedLevel(string $steamid3, string $gameMode, string $divisionLabel): void
    {
        DB::table('player_levels')->insert([
            'steamid' => $steamid3,
            'game_mode' => $gameMode,
            'tier_moyen' => 0.0,
            'division_label' => $divisionLabel,
            'nb_matchs_comptes' => 10,
            'nb_competitions' => 1,
            'last_match_time' => time(),
            'computed_at' => time(),
        ]);
    }

    public function test_les_visiteurs_non_connectes_sont_rediriges_vers_login(): void
    {
        $this->get('/joueurs')->assertRedirect('/login');
    }

    public function test_seuls_les_joueurs_inscrits_sont_listes(): void
    {
        $this->seedPlayer('alpha', 'Alpha', '2026-01-01 00:00:00');
        // Joueur importé depuis les logs de matchs : jamais connecté.
        DB::table('players_info')->insert([
            'steamid' => '[U:1:424242]',
            'name' => 'MercJamaisConnecte',
            'display_name' => 'MercJamaisConnecte',
        ]);

        $response = $this->withSession(['steamid' => '76561197960265729'])->get('/joueurs');

        $response->assertOk();
        $response->assertSee('Alpha');
        $response->assertDontSee('MercJamaisConnecte');
    }

    public function test_le_tri_par_division_hl_suit_l_ordre_canonique(): void
    {
        $this->seedPlayer('open', 'JoueurOpen', '2026-01-01 00:00:00');
        $this->seedPlayer('prem', 'JoueurPrem', '2026-01-01 00:00:00');
        $this->seedLevel('[U:1:'.(DB::table('players_info')->where('name', 'open')->value('steamid')), '9v9', 'Open');
        // Le second joueur inséré a le rang HL le plus élevé.
        $premSteamid = (string) DB::table('players_info')->where('name', 'prem')->value('steamid');
        $this->seedLevel($premSteamid, '9v9', 'Premiership');

        $response = $this->withSession(['steamid' => '76561197960265729'])
            ->get('/joueurs?sort=hl&dir=asc');

        $response->assertOk();
        $this->assertTrue(
            mb_strpos((string) $response->getContent(), 'JoueurPrem') < mb_strpos((string) $response->getContent(), 'JoueurOpen')
        );
    }

    public function test_la_recherche_filtre_par_pseudo(): void
    {
        $this->seedPlayer('alpha', 'Alpha', '2026-01-01 00:00:00');
        $this->seedPlayer('beta', 'Beta', '2026-01-02 00:00:00');

        $response = $this->withSession(['steamid' => '76561197960265729'])
            ->get('/joueurs?q=alp');

        $response->assertOk();
        $response->assertSee('Alpha');
        $response->assertDontSee('>Beta<', false);
    }
}
