<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Crons\SyncEtf2lService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Remplacement des rosters ETF2L en base (SyncEtf2lService::replaceRoster) :
 * le roster courant doit remplacer l'ancien, pas s'y cumuler — sinon la fiche
 * d'un match (/match/{id}) affiche tous les joueurs jamais synchronisés,
 * y compris ceux qui ont quitté l'équipe depuis.
 *
 * NB : DatabaseMigrations et non RefreshDatabase — le service ouvre ses
 * propres transactions sur le PDO brut, incompatible avec la transaction
 * de test de RefreshDatabase sur la même connexion.
 */
final class SyncEtf2lServiceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_le_remplacement_supprime_les_joueurs_partis_de_l_equipe(): void
    {
        $this->seedPlayer(100, 1, 'Ancien joueur');
        $this->seedPlayer(100, 2, 'Joueur resté');
        $this->seedPlayer(200, 1, 'Joueur d\'une autre équipe');

        (new SyncEtf2lService)->replaceRoster(100, [
            'name' => 'Les Baguettes',
            'country' => 'France',
            'tag' => 'BAG',
            'players' => [],
        ], [
            ['id' => 2, 'name' => 'Joueur resté', 'role' => 'Member', 'country' => 'france',
                'steam' => ['id64' => '76561198000000002']],
            ['id' => 3, 'name' => 'Nouvelle recrue', 'role' => 'Member', 'country' => 'france',
                'steam' => ['id64' => '76561198000000003']],
        ]);

        $roster = DB::table('etf2l_players')->where('team_id', 100)
            ->orderBy('player_id')->pluck('name')->all();

        // Le joueur parti (id 1) a disparu ; resté + recrue seulement.
        $this->assertSame(['Joueur resté', 'Nouvelle recrue'], $roster);
        $this->assertDatabaseHas('etf2l_teams', ['team_id' => 100, 'name' => 'Les Baguettes', 'tag' => 'BAG']);
    }

    public function test_le_remplacement_ne_touche_pas_les_autres_equipes(): void
    {
        $this->seedPlayer(100, 1, 'Ancien joueur');
        $this->seedPlayer(200, 1, 'Joueur d\'une autre équipe');

        (new SyncEtf2lService)->replaceRoster(100, ['name' => 'Les Baguettes'], []);

        // Roster vidé pour l'équipe 100, intact pour l'équipe 200.
        $this->assertSame(0, DB::table('etf2l_players')->where('team_id', 100)->count());
        $this->assertDatabaseHas('etf2l_players', [
            'team_id' => 200,
            'player_id' => 1,
            'name' => 'Joueur d\'une autre équipe',
        ]);
    }

    public function test_un_roster_vide_vide_la_table_des_joueurs_de_l_equipe(): void
    {
        $this->seedPlayer(100, 1, 'Ancien joueur');
        $this->seedPlayer(100, 2, 'Autre ancien joueur');

        (new SyncEtf2lService)->replaceRoster(100, ['name' => 'Équipe dissoute'], []);

        $this->assertSame(0, DB::table('etf2l_players')->where('team_id', 100)->count());
        $this->assertDatabaseHas('etf2l_teams', ['team_id' => 100, 'name' => 'Équipe dissoute', 'tag' => null]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedPlayer(int $teamId, int $playerId, string $name, array $overrides = []): void
    {
        DB::table('etf2l_players')->insert(array_merge([
            'team_id' => $teamId,
            'player_id' => $playerId,
            'name' => $name,
            'role' => 'Member',
            'country' => 'france',
            'steamid64' => '7656119800000000'.$playerId,
        ], $overrides));
    }
}
