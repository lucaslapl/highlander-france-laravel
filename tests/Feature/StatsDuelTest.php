<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DuelStatsService;
use App\Services\Etf2lHistoryService;
use App\Services\OfficialLogProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatsDuelTest extends TestCase
{
    use RefreshDatabase;

    private const TEAM_ID = 15176;

    private function seedTeam(): void
    {
        DB::table('etf2l_teams')->insert([
            'team_id' => self::TEAM_ID,
            'name' => 'Test Team',
            'country' => 'fr',
            'tag' => 'TT',
        ]);

        DB::table('etf2l_players')->insert([
            ['team_id' => self::TEAM_ID, 'player_id' => 1, 'name' => 'Alpha', 'role' => 'Leader', 'country' => 'fr', 'steamid64' => '76561198000000001'],
            ['team_id' => self::TEAM_ID, 'player_id' => 2, 'name' => 'Beta', 'role' => 'Member', 'country' => 'fr', 'steamid64' => '76561198000000002'],
        ]);
    }

    private function seedSeasons(): void
    {
        foreach ([13, 12, 11, 10] as $comp) {
            DB::table('official_seasons')->insert([
                'competition_id' => $comp,
                'name' => 'Highlander Season '.$comp,
                'category' => 'Highlander Season',
                'type' => 'Highlander',
                'game_mode' => '9v9',
                'season_label' => (string) $comp,
                'fetched_at' => time(),
            ]);
        }
    }

    public function test_la_fenetre_equipe_garde_les_3_dernieres_saisons_par_joueur(): void
    {
        $this->seedTeam();
        $this->seedSeasons();

        // P1 joue sur les 4 saisons (la plus ancienne est hors fenêtre).
        $p1 = '76561198000000001';
        // P2 joue sur 3 saisons seulement.
        $p2 = '76561198000000002';

        $rows = [
            // P1
            [$p1, 101, 13, self::TEAM_ID, 500],
            [$p1, 102, 12, self::TEAM_ID, 400],
            [$p1, 103, 11, self::TEAM_ID, 300],
            [$p1, 104, 10, self::TEAM_ID, 200],
            // P2
            [$p2, 105, 13, self::TEAM_ID, 500],
            [$p2, 106, 11, self::TEAM_ID, 300],
            [$p2, 107, 10, self::TEAM_ID, 200],
        ];

        foreach ($rows as [$sid64, $mid, $comp, $teamId, $time]) {
            DB::table('player_season_matches')->insert([
                'steamid' => \App\Services\SteamId::toSteamId3($sid64),
                'match_id' => $mid,
                'competition_id' => $comp,
                'team_id' => $teamId,
                'opponent_team_id' => 500,
                'game_mode' => '9v9',
                'time' => $time,
                'round' => '',
                'r1' => 3,
                'r2' => 1,
                'team_is_clan1' => 1,
            ]);
        }

        $service = new DuelStatsService(new Etf2lHistoryService, new \App\Models\OfficialMatchRepository, new OfficialLogProcessor);
        $window = $service->teamWindow(self::TEAM_ID, '9v9');

        $matchIds = array_map(static fn (array $m): int => (int) $m['match_id'], $window);

        // Le match 104 (4e saison de P1) est exclu ; les autres sont présents.
        $this->assertNotContains(104, $matchIds);
        foreach ([101, 102, 103, 105, 106, 107] as $expected) {
            $this->assertContains($expected, $matchIds, "match {$expected} attendu dans la fenêtre");
        }
    }

    public function test_le_scoring_officiel_est_oriente_selon_le_camp_du_joueur(): void
    {
        $this->seedTeam();
        $this->seedSeasons();

        // P1 était clan1 (score 3–1), P2 était clan2 dans son match (score 1–3).
        DB::table('player_season_matches')->insert([
            'steamid' => \App\Services\SteamId::toSteamId3('76561198000000001'),
            'match_id' => 201, 'competition_id' => 13, 'team_id' => self::TEAM_ID,
            'opponent_team_id' => 600, 'game_mode' => '9v9', 'time' => 500,
            'round' => '', 'r1' => 3, 'r2' => 1, 'team_is_clan1' => 1,
        ]);
        DB::table('player_season_matches')->insert([
            'steamid' => \App\Services\SteamId::toSteamId3('76561198000000002'),
            'match_id' => 202, 'competition_id' => 13, 'team_id' => self::TEAM_ID,
            'opponent_team_id' => 600, 'game_mode' => '9v9', 'time' => 490,
            'round' => '', 'r1' => 1, 'r2' => 3, 'team_is_clan1' => 0,
        ]);

        $service = new DuelStatsService(new Etf2lHistoryService, new \App\Models\OfficialMatchRepository, new OfficialLogProcessor);
        $window = $service->teamWindow(self::TEAM_ID, '9v9');

        $byMatch = [];
        foreach ($window as $m) {
            $byMatch[(int) $m['match_id']] = $m;
        }

        $this->assertSame(3, $byMatch[201]['team_score']);
        $this->assertSame(1, $byMatch[201]['opponent_score']);
        $this->assertSame(3, $byMatch[202]['team_score']);
        $this->assertSame(1, $byMatch[202]['opponent_score']);
    }
}
