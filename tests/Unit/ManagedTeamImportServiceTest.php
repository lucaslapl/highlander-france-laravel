<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ManagedTeamsRepository;
use App\Services\ManagedTeamImportService;
use App\Services\TeamStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Import / ré-synchronisation d'une équipe ETF2L, sans réseau (closures).
 */
class ManagedTeamImportServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Service branché sur une closure pour `/team/{id}` (roster + méta).
     *
     * @param  array<string, array>  $routes  sous-chaîne d'URL => réponse
     */
    private function importService(array $routes): ManagedTeamImportService
    {
        $etf2l = static function (string $url) use ($routes): ?array {
            foreach ($routes as $needle => $reply) {
                if (str_contains($url, $needle)) {
                    return $reply;
                }
            }

            return null;
        };

        return new ManagedTeamImportService(new TeamStatsService($etf2l), new ManagedTeamsRepository);
    }

    private function teamPayload(): array
    {
        return [
            'status' => ['code' => 200, 'message' => 'OK'],
            'team' => [
                'id' => 15176,
                'name' => 'France',
                'tag' => 'FRANCE',
                'country' => 'France',
                'competitions' => [
                    ['competition' => 'Highlander Season 12', 'category' => 'Highlander Season', 'division' => ['name' => 'High', 'tier' => 3]],
                ],
                'players' => [
                    ['id' => 501, 'name' => 'Psycho', 'role' => 'Member', 'country' => 'France', 'steam' => ['id64' => '76561198084918608']],
                    ['id' => 502, 'name' => 'AsuX', 'role' => 'Deputy', 'country' => 'Belgium', 'steam' => ['id64' => '76561197990333289']],
                ],
            ],
        ];
    }

    public function test_import_cree_equipe_et_roster_depuis_etf2l(): void
    {
        $service = $this->importService(['/team/15176' => $this->teamPayload()]);

        $result = $service->import(15176);

        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['team']);

        $team = DB::table('managed_teams')->where('etf2l_team_id', 15176)->first();
        $this->assertNotNull($team);
        $this->assertSame('France', $team->name);
        $this->assertSame('FRANCE', $team->tag);
        $this->assertSame('high', $team->division);
        $this->assertSame('9v9', $team->format);
        $this->assertSame(0, (int) $team->is_active);

        $members = DB::table('managed_team_members')->where('team_id', $team->id)->orderBy('id')->get();
        $this->assertCount(2, $members);
        $this->assertSame('etf2l', $members[0]->source);
        $this->assertSame(501, (int) $members[0]->etf2l_player_id);
        $this->assertSame('76561198084918608', $members[0]->steamid64);
        $this->assertSame('belgium', $members[1]->country);
    }

    public function test_import_suggere_le_format_6v6(): void
    {
        $payload = $this->teamPayload();
        $payload['team']['competitions'] = [
            ['competition' => 'ETF2L 6v6 Season 52', 'category' => '6v6 Season', 'type' => '6v6', 'division' => ['name' => 'High', 'tier' => 3]],
        ];

        $result = $this->importService(['/team/15176' => $payload])->import(15176);

        $this->assertTrue($result['ok']);
        $this->assertSame('6v6', DB::table('managed_teams')->where('etf2l_team_id', 15176)->value('format'));
    }

    public function test_import_refuse_une_equipe_deja_geree(): void
    {
        DB::table('managed_teams')->insert([
            'slug' => 'france', 'name' => 'France', 'etf2l_team_id' => 15176,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->importService(['/team/15176' => $this->teamPayload()])->import(15176);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('déjà gérée', $result['error']);
    }

    public function test_import_echoue_si_equipe_introuvable(): void
    {
        $result = $this->importService([])->import(999999);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('introuvable', $result['error']);
    }

    public function test_sync_roster_preserve_les_membres_manuels_et_leaders(): void
    {
        $teamId = DB::table('managed_teams')->insertGetId([
            'slug' => 'france', 'name' => 'France', 'etf2l_team_id' => 15176,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('managed_team_members')->insert([
            // Manuel + leader : ne doit pas être écrasé.
            ['team_id' => $teamId, 'steamid64' => '76561198000000001', 'steam_name' => 'Capitaine',
                'class' => 'scout', 'status' => 'starter', 'is_leader' => 1, 'source' => 'manual',
                'created_at' => now(), 'updated_at' => now()],
            // Membre ETF2L renommé dans le nouveau roster.
            ['team_id' => $teamId, 'steamid64' => '76561197990333289', 'steam_name' => 'AncienNom',
                'class' => 'medic', 'status' => 'starter', 'is_leader' => 0, 'source' => 'etf2l',
                'created_at' => now(), 'updated_at' => now()],
            // Membre ETF2L parti : conservé (jamais supprimé).
            ['team_id' => $teamId, 'steamid64' => '76561198000000003', 'steam_name' => 'Parti',
                'class' => null, 'status' => 'backup', 'is_leader' => 0, 'source' => 'etf2l',
                'created_at' => now(), 'updated_at' => now()],
        ]);

        $payload = $this->teamPayload();
        $payload['team']['players'][] = ['id' => 503, 'name' => 'Nouveau', 'role' => 'Member', 'country' => 'France', 'steam' => ['id64' => '76561198000000004']];

        $result = $this->importService(['/team/15176' => $payload])->syncRoster($teamId);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(1, $result['departed']);

        $manual = DB::table('managed_team_members')->where('team_id', $teamId)->where('steamid64', '76561198000000001')->first();
        $this->assertSame('Capitaine', $manual->steam_name);
        $this->assertSame('scout', $manual->class);
        $this->assertSame(1, (int) $manual->is_leader);

        $renamed = DB::table('managed_team_members')->where('team_id', $teamId)->where('steamid64', '76561197990333289')->first();
        $this->assertSame('AsuX', $renamed->steam_name);
        $this->assertSame(502, (int) $renamed->etf2l_player_id);
        $this->assertSame('medic', $renamed->class, 'La classe choisie manuellement est préservée.');

        $this->assertDatabaseHas('managed_team_members', ['team_id' => $teamId, 'steamid64' => '76561198000000003']);
        $this->assertDatabaseHas('managed_team_members', ['team_id' => $teamId, 'steamid64' => '76561198000000004', 'source' => 'etf2l']);
    }
}
