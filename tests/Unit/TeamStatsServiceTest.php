<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TeamStatsService;
use Tests\TestCase;

class TeamStatsServiceTest extends TestCase
{
    /**
     * Service branché sur des closures au lieu de l'API (aucun réseau, aucun disque).
     *
     * @param  array<string, array>  $etf2lRoutes  sous-chaîne d'URL => réponse
     * @param  array<string, array>  $logsRoutes  sous-chaîne d'URL => réponse
     */
    private function service(array $etf2lRoutes = [], array $logsRoutes = []): TeamStatsService
    {
        $etf2l = static function (string $url) use ($etf2lRoutes): ?array {
            foreach ($etf2lRoutes as $needle => $reply) {
                if (str_contains($url, $needle)) {
                    return $reply;
                }
            }

            return null;
        };

        $logs = static function (string $url) use ($logsRoutes): ?array {
            foreach ($logsRoutes as $needle => $reply) {
                if (str_contains($url, $needle)) {
                    return $reply;
                }
            }

            return null;
        };

        return new TeamStatsService($etf2l, $logs);
    }

    private function teamPayload(array $players, string $name = 'France', ?string $tag = 'FRANCE'): array
    {
        return [
            'status' => ['code' => 200, 'message' => 'OK'],
            'team' => [
                'id' => 15176,
                'name' => $name,
                'tag' => $tag,
                'country' => 'France',
                'players' => $players,
            ],
        ];
    }

    // ─── Roster ───────────────────────────────────────────────────────────

    public function test_fetch_roster_parse_les_membres_avec_steamid64(): void
    {
        $payload = $this->teamPayload([
            ['name' => 'Psycho', 'role' => 'Member', 'steam' => ['id64' => '76561198084918608']],
            ['name' => 'AsuX', 'role' => 'Deputy', 'steam' => ['id64' => '76561197990333289']],
            // Membre sans steamid64 : ignoré.
            ['name' => 'SansSteam', 'role' => 'Member', 'steam' => ['id64' => null]],
        ]);

        $roster = $this->service(['/team/15176' => $payload])->fetchRoster(15176);

        $this->assertNotNull($roster);
        $this->assertSame('France', $roster['name']);
        $this->assertSame('FRANCE', $roster['tag']);
        $this->assertCount(2, $roster['players']);
        $this->assertSame('76561198084918608', $roster['players'][0]['steamid64']);
        $this->assertSame('Deputy', $roster['players'][1]['role']);
    }

    public function test_fetch_roster_retourne_null_sur_equipe_introuvable(): void
    {
        $service = $this->service(['/team/9999' => ['status' => ['code' => 404]]]);
        $this->assertNull($service->fetchRoster(9999));
    }

    // ─── Résultats + winrate par compétition ─────────────────────────────

    public function test_group_competitions_calcule_le_winrate_par_competition_et_mode(): void
    {
        $teamId = 15176;

        $results = [
            // 6v6 Season : clan2 = nous, victoire.
            [
                'clan1' => ['id' => 11, 'name' => 'A'],
                'clan2' => ['id' => $teamId],
                'r1' => 0, 'r2' => 2,
                'competition' => ['id' => 1, 'name' => '6v6 Season 1', 'category' => '6v6 Season', 'type' => '6v6'],
            ],
            // 6v6 Season : défaite.
            [
                'clan1' => ['id' => $teamId],
                'clan2' => ['id' => 22, 'name' => 'B'],
                'r1' => 1, 'r2' => 2,
                'competition' => ['id' => 1, 'name' => '6v6 Season 1', 'category' => '6v6 Season', 'type' => '6v6'],
            ],
            // Highlander Nations : type national → 9v9, victoire.
            [
                'clan1' => ['id' => $teamId],
                'clan2' => ['id' => 33, 'name' => 'C'],
                'r1' => 2, 'r2' => 1,
                'competition' => ['id' => 2, 'name' => 'Highlander Nations Cup #9', 'category' => "Nations' Cup", 'type' => 'National Highlander Team'],
            ],
            // Match nul : compté en draw.
            [
                'clan1' => ['id' => $teamId],
                'clan2' => ['id' => 44, 'name' => 'D'],
                'r1' => 1, 'r2' => 1,
                'competition' => ['id' => 1, 'name' => '6v6 Season 1', 'category' => '6v6 Season', 'type' => '6v6'],
            ],
            // Résultat d'une autre équipe : ignoré.
            [
                'clan1' => ['id' => 55, 'name' => 'E'],
                'clan2' => ['id' => 66, 'name' => 'F'],
                'r1' => 2, 'r2' => 0,
                'competition' => ['id' => 1, 'name' => '6v6 Season 1', 'category' => '6v6 Season', 'type' => '6v6'],
            ],
        ];

        $byMode = (new TeamStatsService)->groupCompetitions($teamId, $results);

        $this->assertArrayHasKey('6s', $byMode);
        $this->assertArrayHasKey('9v9', $byMode);

        $six = $byMode['6s'][0];
        $this->assertSame(1, $six['id']);
        $this->assertSame(3, $six['total']);
        $this->assertSame(1, $six['wins']);
        $this->assertSame(1, $six['losses']);
        $this->assertSame(1, $six['draws']);
        $this->assertSame(50, $six['winrate']);

        $hl = $byMode['9v9'][0];
        $this->assertSame(2, $hl['id']);
        $this->assertSame(1, $hl['wins']);
        $this->assertSame(100, $hl['winrate']);
    }

    public function test_group_competitions_ignore_les_doublons_de_resultat(): void
    {
        // L'API team/{id}/results renvoie un enregistrement par joueur du roster
        // pour chaque match : même `result` répété ~roster fois.
        $teamId = 15176;

        $win = [
            'clan1' => ['id' => $teamId],
            'clan2' => ['id' => 22, 'name' => 'B'],
            'r1' => 2, 'r2' => 1,
            'result' => 555,
            'competition' => ['id' => 7, 'name' => 'Highlander Season 1', 'category' => 'Highlander Season', 'type' => 'highlander'],
        ];
        $loss = [
            'clan1' => ['id' => $teamId],
            'clan2' => ['id' => 23, 'name' => 'C'],
            'r1' => 0, 'r2' => 2,
            'result' => 556,
            'competition' => ['id' => 7, 'name' => 'Highlander Season 1', 'category' => 'Highlander Season', 'type' => 'highlander'],
        ];

        $results = array_merge([$win], array_fill(0, 15, $win), [$loss, $loss]);

        $hl = (new TeamStatsService)->groupCompetitions($teamId, $results)['9v9'][0];

        $this->assertSame(2, $hl['total']);
        $this->assertSame(1, $hl['wins']);
        $this->assertSame(1, $hl['losses']);
        $this->assertSame(50, $hl['winrate']);
    }

    // ─── Détection du mode ────────────────────────────────────────────────
    public function test_mode_detecte_competitions(): void
    {
        $map = TeamStatsService::class;

        $this->assertSame('6s', $map::gameModeFromCompetition('6v6', '6v6 Season', '6v6 Season 50'));
        $this->assertSame('9v9', $map::gameModeFromCompetition('highlander', 'Highlander Season', 'Highlander Season 12'));
        $this->assertSame('9v9', $map::gameModeFromCompetition('National Highlander Team', "Nations' Cup", 'Highlander Nations Cup'));
        $this->assertSame('6s', $map::gameModeFromCompetition('National 6v6 Team', "Nations' Cup", '6v6 Nations Cup'));
        // Repli sur le nom quand le type est inconnu.
        $this->assertNull($map::gameModeFromCompetition('', '', 'Compétition bizarre'));
        $this->assertSame('9v9', $map::gameModeFromCompetition('', '', 'Highlander Open'));
        $this->assertSame('6s', $map::gameModeFromCompetition('', '', 'ETF2L 6v6 Season 52'));
    }

    public function test_mode_detecte_titres_de_logs(): void
    {
        $map = TeamStatsService::class;

        $this->assertSame('6s', $map::gameModeFromLogTitle('ETF2L 6v6 Season 52'));
        $this->assertSame('6s', $map::gameModeFromLogTitle('serveme.tf [6s] match #1'));
        $this->assertSame('6s', $map::gameModeFromLogTitle('ETF2L S52 6s'));
        $this->assertSame('9v9', $map::gameModeFromLogTitle('HL: France vs Finland'));
        $this->assertSame('9v9', $map::gameModeFromLogTitle('Highlander Nations Cup'));
        $this->assertSame('9v9', $map::gameModeFromLogTitle('serveme.tf #1343866')); // défaut
    }

    // ─── Découverte des logs ──────────────────────────────────────────────

    public function test_discover_logs_filtre_et_classe_par_mode(): void
    {
        $logsReply = [
            'logs' => [
                ['id' => 101, 'title' => 'ETF2L 6v6 Season 52', 'map' => 'product', 'date' => 1700000000, 'players' => 18],
                ['id' => 102, 'title' => 'France vs Finland', 'map' => 'proot', 'date' => 1690000000, 'players' => 18],
                ['id' => 0, 'title' => 'ignoré (id nul)', 'map' => '', 'date' => 0, 'players' => 0],
            ],
        ];

        $logs = $this->service([], ['player=76561198084918608' => $logsReply])
            ->discoverLogs(['76561198084918608']);

        $this->assertCount(2, $logs);
        $this->assertSame('6s', $logs[0]['mode']);   // le plus récent d'abord
        $this->assertSame(101, $logs[0]['id']);
        $this->assertSame('9v9', $logs[1]['mode']);
    }

    public function test_discover_logs_sans_roster_retourne_vide(): void
    {
        $this->assertSame([], (new TeamStatsService)->discoverLogs([]));
    }

    // ─── Agrégation des stats du roster ───────────────────────────────────

    public function test_aggregate_roster_cumule_les_stats_par_joueur(): void
    {
        $alpha = '76561198012345678'; // [U:1:52079950]
        $beta = '76561197990333289';  // [U:1:30067561]

        $roster = [
            ['steamid64' => $alpha, 'name' => 'Alpha', 'role' => 'Member'],
            ['steamid64' => $beta, 'name' => 'Beta', 'role' => 'Deputy'],
        ];

        $fixtures = [
            1001 => $this->logDetails([
                ['steamid' => '[U:1:52079950]', 'row' => $this->playerRow(['dapm' => 300, 'dmg' => 10000, 'kills' => 10, 'deaths' => 4, 'heal' => 2000])],
            ]),
            1002 => $this->logDetails([
                ['steamid' => '[U:1:52079950]', 'row' => $this->playerRow(['dapm' => 200, 'dmg' => 8000, 'kills' => 5, 'deaths' => 10, 'heal' => 1000])],
                ['steamid' => '[U:1:30067561]', 'row' => $this->playerRow(['dapm' => 250, 'dmg' => 5000, 'kills' => 6, 'deaths' => 2, 'heal' => 500])],
            ]),
        ];

        $fetcher = static fn (int $id): ?array => $fixtures[$id] ?? null;

        $result = (new TeamStatsService)->aggregateRoster($roster, [1001, 1002], $fetcher);

        $this->assertCount(2, $result['players']);
        $this->assertCount(2, $result['logs']);

        $byName = [];
        foreach ($result['players'] as $p) {
            $byName[$p['name']] = $p;
        }

        $this->assertSame([2, 15, 14, 18000, 3000, 1.07, 250], [
            $byName['Alpha']['matches'], $byName['Alpha']['kills'], $byName['Alpha']['deaths'],
            $byName['Alpha']['dmg'], $byName['Alpha']['heal'], $byName['Alpha']['kd'], $byName['Alpha']['dpm'],
        ]);
        $this->assertSame([1, 6, 2, 5000, 500, 3.0, 250], [
            $byName['Beta']['matches'], $byName['Beta']['kills'], $byName['Beta']['deaths'],
            $byName['Beta']['dmg'], $byName['Beta']['heal'], $byName['Beta']['kd'], $byName['Beta']['dpm'],
        ]);

        $this->assertTrue($result['logs'][0]['found']);
        $this->assertSame(1, $result['logs'][0]['present']);
        $this->assertSame(2, $result['logs'][1]['present']);
    }

    public function test_aggregate_roster_trace_un_log_en_erreur(): void
    {
        $roster = [['steamid64' => '76561198012345678', 'name' => 'Alpha', 'role' => 'Member']];
        // Aucune fixture pour le log 3003 => échec de récupération.
        $fetcher = static fn (int $id): ?array => null;

        $result = (new TeamStatsService)->aggregateRoster($roster, [3003], $fetcher);

        $this->assertCount(1, $result['logs']);
        $this->assertFalse($result['logs'][0]['found']);
        $this->assertNotNull($result['logs'][0]['error']);

        // Le joueur reste listé mais sans match comptabilisé.
        $this->assertCount(1, $result['players']);
        $this->assertSame(0, $result['players'][0]['matches']);
        $this->assertSame(0, $result['players'][0]['kills']);
    }

    // ─── Fixtures logs.tf (même contrat que PlayerStatsServiceTest) ──────

    private function logDetails(array $players, int $length = 900): array
    {
        $map = [];
        foreach ($players as $entry) {
            $map[$entry['steamid']] = array_merge(['team' => 'red'], $entry['row']);
        }

        return [
            'length' => $length,
            'teams' => ['Red' => ['score' => 2], 'Blue' => ['score' => 1]],
            'players' => $map,
            'classkills' => [],
        ];
    }

    private function playerRow(array $overrides = []): array
    {
        return array_merge([
            'team' => 'red', 'dapm' => 300, 'dmg' => 10000, 'dt' => 5000,
            'kills' => 10, 'deaths' => 4, 'assists' => 3, 'suicides' => 0,
            'heal' => 2000, 'medkits' => 2, 'medkits_hp' => 120, 'ubers' => 1,
            'drops' => 0, 'backstabs' => 0, 'headshots' => 0, 'as' => 2,
        ], $overrides);
    }
}
