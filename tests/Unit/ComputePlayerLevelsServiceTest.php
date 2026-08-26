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

    public function test_exclut_forfaits_divisions_non_normalisables_et_coupes(): void
    {
        $results = [
            // Forfait : exclu.
            $this->apiResult('Highlander', 1, 'Premiership', team: 10, defaultwin: true),
            // Coupe (catégorie non officielle) : exclue même avec division connue.
            $this->apiResult('Highlander', 2, 'Premiership', team: 10, category: 'Highlander Cup'),
            // Division de coupe non normalisable : exclu.
            $this->apiResult('6v6', 3, 'Megalodon', team: 10),
            // Match valide.
            $this->apiResult('Highlander', 4, 'Division 1', team: 10),
        ];

        $levels = $this->service->levelsFromResults($results);

        $this->assertCount(1, $levels);
        $this->assertSame('9v9', $levels[0]['game_mode']);
        // "Division 1" (vieille époque HL) => High via le rang 1.
        $this->assertSame('High', $levels[0]['division_label']);
        $this->assertSame(1.0, $levels[0]['tier_moyen']);
        $this->assertSame(1, $levels[0]['nb_matchs_comptes']);
    }

    public function test_les_mercs_dans_une_autre_equipe_sont_exclus(): void
    {
        $results = [
            // Compétition principale : 4 matchs avec l'équipe 10.
            $this->apiResult('6v6', 100, 'Division 2', team: 10, time: 1000),
            $this->apiResult('6v6', 100, 'Division 2', team: 10, time: 1100),
            $this->apiResult('6v6', 100, 'Division 2', team: 10, time: 1200),
            $this->apiResult('6v6', 100, 'Division 2', team: 10, time: 1300),
            // Remplacement dans l'équipe 99 (minoritaire) : ignoré.
            $this->apiResult('6v6', 100, 'Division 2', team: 99, time: 1400),
        ];

        $levels = $this->service->levelsFromResults($results);

        $this->assertCount(1, $levels);
        $this->assertSame(4, $levels[0]['nb_matchs_comptes']);
        $this->assertSame(1, $levels[0]['nb_competitions']);
    }

    public function test_separe_9v9_et_6v6_et_garde_les_trois_dernieres_saisons(): void
    {
        $results = [];

        // 4 saisons Highlander (seules les 3 plus récentes comptent).
        foreach ([100 => 100, 101 => 200, 102 => 300, 103 => 400] as $compId => $time) {
            for ($i = 0; $i < 2; $i++) {
                $results[] = $this->apiResult('Highlander', $compId, 'Mid', team: 10, time: $time);
            }
        }
        // Une vieille saison Highlander (écartée).
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->apiResult('Highlander', 104, 'Open', team: 10, time: 50);
        }
        // Une saison 6v6 indépendante.
        $results[] = $this->apiResult('6v6', 200, 'Top Division', team: 20);

        $levels = $this->service->levelsFromResults($results);

        $this->assertCount(2, $levels);

        // Trié par nom de mode : '6s' avant '9v9'.
        $sixes = $levels[0];
        $this->assertSame('6s', $sixes['game_mode']);
        $this->assertSame('Top Division', $sixes['division_label']);

        $hl = $levels[1];
        $this->assertSame('9v9', $hl['game_mode']);
        $this->assertSame(6, $hl['nb_matchs_comptes']);
        $this->assertSame(3, $hl['nb_competitions']);
        $this->assertSame(2.0, $hl['tier_moyen']);
        $this->assertSame('Mid', $hl['division_label']);
    }

    public function test_le_libelle_correspond_au_rang_moyen_arrondi(): void
    {
        $results = [
            $this->apiResult('Highlander', 100, 'Premiership', team: 10, time: 300),
            $this->apiResult('Highlander', 100, 'High', team: 10, time: 310),
            $this->apiResult('Highlander', 100, 'High', team: 10, time: 320),
        ];

        $levels = $this->service->levelsFromResults($results);

        // Moyenne 2/3 sur les rangs : arrondie au rang 1 => High.
        $this->assertEqualsWithDelta(2 / 3, $levels[0]['tier_moyen'], 0.01);
        $this->assertSame('High', $levels[0]['division_label']);
    }

    public function test_les_vieilles_divisions_6v6_sont_normalisees_vers_low(): void
    {
        $results = [
            // Open et Division 5/6 d'époque => Low aujourd'hui.
            $this->apiResult('6v6', 100, 'Open', team: 10, time: 300),
            $this->apiResult('6v6', 101, 'Division 5D', team: 10, time: 200),
            $this->apiResult('6v6', 102, 'Division 6B', team: 10, time: 100),
        ];

        $levels = $this->service->levelsFromResults($results);

        $this->assertCount(1, $levels);
        $this->assertSame('Low', $levels[0]['division_label']);
        $this->assertSame(3, $levels[0]['nb_matchs_comptes']);
    }

    public function test_la_vieille_division_mid_6v6_correspond_a_division_3(): void
    {
        $results = [
            $this->apiResult('6v6', 100, 'Mid', team: 10),
        ];

        $levels = $this->service->levelsFromResults($results);

        $this->assertCount(1, $levels);
        $this->assertSame(2.0, $levels[0]['tier_moyen']);
        $this->assertSame('Division 3', $levels[0]['division_label']);
    }

    public function test_les_vieilles_divisions_hl_sont_normalisees_selon_lechelle_legacy(): void
    {
        $results = [
            $this->apiResult('Highlander', 100, 'Division 1', team: 10, time: 500),
            $this->apiResult('Highlander', 101, 'Division 2A', team: 10, time: 400),
            $this->apiResult('Highlander', 102, 'Division 3', team: 10, time: 300),
            $this->apiResult('Highlander', 103, 'Division 4B', team: 10, time: 200),
            $this->apiResult('Highlander', 104, 'Division 5D', team: 10, time: 100),
            $this->apiResult('Highlander', 105, 'Division 6M', team: 10),
        ];

        $levels = $this->service->levelsFromResults($results);

        // Seules les 3 saisons les plus récentes comptent (Div1, Div2A, Div3)
        // => rangs 1 + 1 + 2, moyenne 1.33 arrondie au rang 1 => High.
        $this->assertEqualsWithDelta(4 / 3, $levels[0]['tier_moyen'], 0.01);
        $this->assertSame('High', $levels[0]['division_label']);
        $this->assertSame(3, $levels[0]['nb_matchs_comptes']);
    }

    public function test_l_annee_du_dernier_match_pris_en_compte_est_stockee(): void
    {
        $results = [
            $this->apiResult('Highlander', 100, 'Open A', team: 10, time: strtotime('2017-03-15')),
            $this->apiResult('Highlander', 101, 'Mid', team: 10, time: strtotime('2016-02-10')),
        ];

        $levels = $this->service->levelsFromResults($results);

        $this->assertCount(1, $levels);
        $this->assertSame(strtotime('2017-03-15'), $levels[0]['last_match_time']);
    }

    /**
     * Fabrique un résultat brut au format de l'API ETF2L v2.
     */
    private function apiResult(
        string $type,
        int $competitionId,
        ?string $division,
        int $team,
        int $time = 0,
        bool $defaultwin = false,
        string $category = 'Highlander Season',
    ): array {
        return [
            'clan1' => ['id' => $team, 'was_in_team' => true],
            'clan2' => ['id' => $team + 1000, 'was_in_team' => false],
            'competition' => [
                'id' => $competitionId,
                'name' => 'Comp ' . $competitionId,
                'type' => $type,
                'category' => $category,
            ],
            'defaultwin' => $defaultwin,
            'division' => $division === null ? null : ['id' => 1, 'name' => $division, 'tier' => 0],
            'r1' => 6,
            'r2' => 0,
            'round' => 'Week 1',
            'time' => $time,
            'week' => 1,
        ];
    }
}
