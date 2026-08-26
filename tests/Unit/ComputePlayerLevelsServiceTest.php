<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Crons\ComputePlayerLevelsService;
use Tests\TestCase;

final class ComputePlayerLevelsServiceTest extends TestCase
{
    private ComputePlayerLevelsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComputePlayerLevelsService();
    }

    public function test_exclut_forfaits_matchs_sans_division_et_modes_inconnus(): void
    {
        $results = [
            $this->apiResult('Highlander', 1, 'Premiership', 0, team: 10, defaultwin: true),
            $this->apiResult('Highlander', 1, null, null, team: 10),
            $this->apiResult('Ultiduo', 2, 'Gold', 0, team: 10),
            $this->apiResult('Highlander', 3, 'Division 1', 1, team: 10),
        ];

        $levels = $this->service->levelsFromResults($results);

        $this->assertCount(1, $levels);
        $this->assertSame('9v9', $levels[0]['game_mode']);
        $this->assertSame(1.0, $levels[0]['tier_moyen']);
        $this->assertSame(1, $levels[0]['nb_matchs_comptes']);
    }

    public function test_les_mercs_dans_une_autre_equipe_sont_exclus(): void
    {
        $results = [
            // Compétition principale : 4 matchs avec l'équipe 10.
            $this->apiResult('6v6', 100, 'Division 2', 2, team: 10, time: 1000),
            $this->apiResult('6v6', 100, 'Division 2', 2, team: 10, time: 1100),
            $this->apiResult('6v6', 100, 'Division 2', 2, team: 10, time: 1200),
            $this->apiResult('6v6', 100, 'Division 2', 2, team: 10, time: 1300),
            // Remplacement dans l'équipe 99 (minoritaire) : ignoré.
            $this->apiResult('6v6', 100, 'Division 2', 2, team: 99, time: 1400),
        ];

        $levels = $this->service->levelsFromResults($results);

        $this->assertCount(1, $levels);
        $this->assertSame(4, $levels[0]['nb_matchs_comptes']);
        $this->assertSame(1, $levels[0]['nb_competitions']);
    }

    public function test_separe_9v9_et_6v6_et_garde_les_trois_dernieres_competitions(): void
    {
        $results = [];

        // 4 compétitions Highlander (seules les 3 plus récentes comptent).
        foreach ([100 => 100, 101 => 200, 102 => 300, 103 => 400] as $compId => $time) {
            for ($i = 0; $i < 2; $i++) {
                $results[] = $this->apiResult('Highlander', $compId, 'Division 3', 3, team: 10, time: $time);
            }
        }
        // Une vieille compétition Highlander (écartée).
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->apiResult('Highlander', 104, 'Division 4', 4, team: 10, time: 50);
        }
        // Une compétition 6v6 indépendante.
        $results[] = $this->apiResult('6v6', 200, 'Mid', 2, team: 20);

        $levels = $this->service->levelsFromResults($results);

        $this->assertCount(2, $levels);

        // Trié par nom de mode : '6s' avant '9v9'.
        $sixes = $levels[0];
        $this->assertSame('6s', $sixes['game_mode']);
        $this->assertSame('Mid', $sixes['division_label']);

        $hl = $levels[1];
        $this->assertSame('9v9', $hl['game_mode']);
        $this->assertSame(6, $hl['nb_matchs_comptes']);
        $this->assertSame(3, $hl['nb_competitions']);
        $this->assertSame(3.0, $hl['tier_moyen']);
    }

    public function test_le_libelle_correspond_au_tier_moyen_arrondi(): void
    {
        $results = [
            $this->apiResult('Highlander', 100, 'Premiership', 0, team: 10, time: 300),
            $this->apiResult('Highlander', 100, 'Division 1', 1, team: 10, time: 310),
            $this->apiResult('Highlander', 100, 'Division 1', 1, team: 10, time: 320),
        ];

        $levels = $this->service->levelsFromResults($results);

        // Moyenne 2/3 : arrondie au tier 1 => Division 1.
        $this->assertEqualsWithDelta(2 / 3, $levels[0]['tier_moyen'], 0.01);
        $this->assertSame('Division 1', $levels[0]['division_label']);
    }

    /**
     * Fabrique un résultat brut au format de l'API ETF2L v2.
     */
    private function apiResult(
        string $type,
        int $competitionId,
        ?string $division,
        ?int $tier,
        int $team,
        int $time = 0,
        bool $defaultwin = false,
    ): array {
        return [
            'clan1' => ['id' => $team, 'was_in_team' => true],
            'clan2' => ['id' => $team + 1000, 'was_in_team' => false],
            'competition' => ['id' => $competitionId, 'name' => 'Comp ' . $competitionId, 'type' => $type],
            'defaultwin' => $defaultwin,
            'division' => $division === null ? null : ['id' => 1, 'name' => $division, 'tier' => $tier],
            'r1' => 6,
            'r2' => 0,
            'round' => 'Week 1',
            'time' => $time,
            'week' => 1,
        ];
    }
}
