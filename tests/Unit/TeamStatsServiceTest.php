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

    // ─── Derniers matchs (pages équipes) ──────────────────────────────────

    public function test_fetch_recent_results_dedoublonne_et_normalise(): void
    {
        $teamId = 15176;

        $win = [
            'result' => 9001,
            'time' => 1700000000,
            'round' => 'Week 1',
            'clan1' => ['id' => $teamId, 'name' => 'France', 'country' => 'France'],
            'clan2' => ['id' => 42, 'name' => 'Germany', 'country' => 'Germany'],
            'r1' => 2, 'r2' => 1,
            'competition' => ['name' => 'Highlander Season 12', 'category' => 'Highlander Season'],
        ];
        $loss = [
            'result' => 9002,
            'time' => 1690000000,
            'clan1' => ['id' => 42, 'name' => 'Germany'],
            'clan2' => ['id' => $teamId, 'name' => 'France'],
            'r1' => 3, 'r2' => 0,
            'competition' => ['name' => 'Highlander Season 12', 'category' => 'Highlander Season'],
        ];
        // L'API renvoie une ligne par joueur : même `result` répété.
        $data = ['data' => array_merge([$win], array_fill(0, 8, $win), [$loss])];

        $recent = $this->service(['/team/15176/results' => $data])->fetchRecentResults($teamId, 5);

        $this->assertCount(2, $recent);
        $this->assertSame(9001, $recent[0]['match_id']);
        $this->assertSame(2, $recent[0]['score_ours']);
        $this->assertSame(1, $recent[0]['score_theirs']);
        $this->assertTrue($recent[0]['won']);
        $this->assertSame('Germany', $recent[0]['opponent']['name']);
        $this->assertSame('Highlander Season 12', $recent[0]['competition_name']);

        // Équipe en clan2 : score inversé et défaite 0-3.
        $this->assertSame(9002, $recent[1]['match_id']);
        $this->assertSame(0, $recent[1]['score_ours']);
        $this->assertSame(3, $recent[1]['score_theirs']);
        $this->assertFalse($recent[1]['won']);
    }

    public function test_fetch_recent_results_pagine_jusqu_au_nombre_attendu(): void
    {
        $teamId = 15176;

        // L'API renvoie une ligne par joueur : jusqu'à ~18 lignes pour un match.
        $make = static function (int $resultId, int $time) use ($teamId): array {
            $row = [
                'result' => $resultId,
                'time' => $time,
                'round' => 'Week 1',
                'clan1' => ['id' => $teamId, 'name' => 'France', 'country' => 'France'],
                'clan2' => ['id' => 42, 'name' => 'Germany', 'country' => 'Germany'],
                'r1' => 2, 'r2' => 1,
                'competition' => ['name' => 'Highlander Season', 'category' => 'Highlander Season'],
            ];

            return array_merge([$row], array_fill(0, 17, $row));
        };

        // Page 1 : un seul match (20 lignes). Page 2 : trois autres matchs.
        $page1 = ['data' => $make(9001, 1700000000), 'last_page' => 2];
        $page2 = ['data' => array_merge($make(9004, 1700000003), $make(9003, 1700000002), $make(9002, 1700000001)), 'last_page' => 2];

        $service = $this->service([
            '/team/15176/results?limit=100&page=1' => $page1,
            '/team/15176/results?limit=100&page=2' => $page2,
        ]);

        $recent = $service->fetchRecentResults($teamId, 4);

        $this->assertCount(4, $recent);
        // Du plus récent au plus ancien.
        $this->assertSame([9004, 9003, 9002, 9001], array_column($recent, 'match_id'));
    }

    public function test_fetch_recent_results_sans_donnee_retourne_vide(): void
    {
        $this->assertSame([], $this->service([])->fetchRecentResults(15176));
    }

    // ─── Méta équipe + division suggérée ─────────────────────────────────

    public function test_fetch_team_meta_suggere_la_saison_recente_puis_le_tier_le_plus_haut(): void
    {
        $payload = [
            'status' => ['code' => 200],
            'team' => [
                'id' => 15176,
                'name' => 'France',
                'tag' => 'FRANCE',
                'country' => 'France',
                'competitions' => [
                    ['competition' => 'Highlander Season 10', 'category' => 'Highlander Season', 'division' => ['name' => 'Low', 'tier' => 4]],
                    ['competition' => 'Highlander Season 12', 'category' => 'Highlander Season', 'division' => ['name' => 'Open', 'tier' => 5]],
                    ['competition' => 'Highlander Season 12', 'category' => 'Highlander Season', 'division' => ['name' => 'High', 'tier' => 3]],
                ],
            ],
        ];

        $meta = $this->service(['/team/15176' => $payload])->fetchTeamMeta(15176);

        $this->assertNotNull($meta);
        $this->assertSame('France', $meta['name']);
        $this->assertSame('high', $meta['suggested_division']);
        $this->assertSame('9v9', $meta['suggested_format']);
    }

    public function test_fetch_team_meta_suggere_le_format_6v6(): void
    {
        $payload = [
            'status' => ['code' => 200],
            'team' => [
                'id' => 15176,
                'name' => 'France',
                'competitions' => [
                    ['competition' => 'ETF2L 6v6 Season 52', 'category' => '6v6 Season', 'type' => '6v6', 'division' => ['name' => 'High', 'tier' => 3]],
                ],
            ],
        ];

        $meta = $this->service(['/team/15176' => $payload])->fetchTeamMeta(15176);

        $this->assertNotNull($meta);
        $this->assertSame('6v6', $meta['suggested_format']);
    }

    public function test_fetch_team_meta_ignore_les_divisions_inconnues(): void
    {
        $payload = [
            'status' => ['code' => 200],
            'team' => [
                'id' => 15176,
                'name' => 'France',
                'competitions' => [
                    ['competition' => 'Bizarre Cup', 'category' => 'Fun', 'division' => ['name' => 'Platinum', 'tier' => 1]],
                ],
            ],
        ];

        $meta = $this->service(['/team/15176' => $payload])->fetchTeamMeta(15176);

        $this->assertNotNull($meta);
        $this->assertNull($meta['suggested_division']);
        $this->assertSame('9v9', $meta['suggested_format']);
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
