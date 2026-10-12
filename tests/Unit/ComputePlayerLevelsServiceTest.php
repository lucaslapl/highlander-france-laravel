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
        $this->service = new ComputePlayerLevelsService;
    }

    public function test_exclut_forfaits_coupes_qualifications_et_divisions_inconnues(): void
    {
        $now = time();
        $results = [
            // Forfait : exclu.
            $this->apiResult('Highlander', 1, 'Premiership', team: 10, time: $now - 900, defaultwin: true),
            // Coupe (catégorie non officielle) : exclue même avec division connue.
            $this->apiResult('Highlander', 2, 'Premiership', team: 10, time: $now - 890, category: 'Highlander Cup'),
            // Qualifications (pre-season) : compétition exclue par la règle 2.7.
            $this->apiResult('Highlander', 3, 'Premiership', team: 10, time: $now - 880,
                competitionName: 'Highlander Season 36: Premiership Qualifiers'),
            // Division inconnue sans tier API : exclue.
            $this->apiResult('Highlander', 4, 'Megalodon', team: 10, time: $now - 870),
            // Saison valide : Division 1 HL => High.
            $this->apiResult('Highlander', 5, 'Division 1', team: 10, time: $now - 120),
            $this->apiResult('Highlander', 5, 'Division 1', team: 10, time: $now - 110),
            $this->apiResult('Highlander', 5, 'Division 1', team: 10, time: $now - 100),
        ];

        $levels = $this->service->levelsFromResults($results);

        // Une ligne propre au 9v9 + une ligne convertie en 6s (High -> D4).
        $this->assertCount(2, $levels);

        $sixes = $this->levelOf($levels, '6s');
        $this->assertSame('Division 4', $sixes['division_label']);
        $this->assertSame(0, $sixes['nb_matchs_comptes']);
        $this->assertSame(0, $sixes['nb_competitions']);
        $this->assertSame(0, $sixes['last_match_time']);

        $hl = $this->levelOf($levels, '9v9');
        $this->assertSame('High', $hl['division_label']);
        $this->assertSame(3, $hl['nb_matchs_comptes']);
        $this->assertSame(1, $hl['nb_competitions']);
    }

    public function test_une_saison_n_est_active_qu_a_partir_de_trois_matchs(): void
    {
        $now = time();
        $results = [
            // Deux matchs seulement : saison non active, aucun niveau.
            $this->apiResult('Highlander', 100, 'Mid', team: 10, time: $now - 100),
            $this->apiResult('Highlander', 100, 'Mid', team: 10, time: $now - 90),
        ];

        $this->assertSame([], $this->service->levelsFromResults($results));
    }

    public function test_les_mercs_dans_une_autre_equipe_sont_exclus(): void
    {
        $now = time();
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('6v6', 100, 'Division 2', team: 10, time: $now - 100 + $i);
        }
        // Remplacement dans l'équipe 99 (minoritaire) : ignoré.
        $results[] = $this->apiResult('6v6', 100, 'Division 2', team: 99, time: $now - 50);

        $levels = $this->service->levelsFromResults($results);

        $sixes = $this->levelOf($levels, '6s');
        $this->assertSame('Division 2', $sixes['division_label']);
        $this->assertSame(3, $sixes['nb_matchs_comptes']);
    }

    public function test_l_experience_est_la_division_la_plus_haute_pas_une_moyenne(): void
    {
        $now = time();
        $results = [];
        // Une saison Premiership puis deux saisons Mid : l'expérience est
        // Premiership (règle 2.7 : division la plus haute), pas une moyenne.
        foreach ([100 => 'Premiership', 101 => 'Mid', 102 => 'Mid'] as $compId => $division) {
            for ($i = 0; $i < 3; $i++) {
                $results[] = $this->apiResult('Highlander', $compId, $division, team: 10, time: $now - 1000 + $compId * 10 + $i);
            }
        }

        $levels = $this->service->levelsFromResults($results);

        $this->assertSame('Premiership', $this->levelOf($levels, '9v9')['division_label']);
        // Conversion officielle : 9v9 Premiership -> 6v6 Division 2.
        $this->assertSame('Division 2', $this->levelOf($levels, '6s')['division_label']);
    }

    public function test_la_fenetre_couvre_les_trois_dernieres_saisons_tous_modes_confondus(): void
    {
        $now = time();
        $results = [];
        // 4 saisons actives : la plus ancienne (Premiership) sort de la
        // fenêtre des 3 dernières (tous modes confondus, règle 2.7) et ne
        // compte donc pas.
        foreach ([
            100 => ['Highlander', 'Premiership', 400],
            101 => ['Highlander', 'Mid', 300],
            102 => ['6v6', 'Division 3', 200],
            103 => ['Highlander', 'Mid', 100],
        ] as $compId => [$type, $division, $age]) {
            for ($i = 0; $i < 3; $i++) {
                $results[] = $this->apiResult($type, $compId, $division, team: 10, time: $now - $age + $i);
            }
        }

        $levels = $this->service->levelsFromResults($results);

        // Fenêtre : les 3 saisons les plus récentes (Mid, D3, Mid) ;
        // la vieille Premiership est hors fenêtre.
        $hl = $this->levelOf($levels, '9v9');
        $this->assertSame('Mid', $hl['division_label']);
        $this->assertSame(2, $hl['nb_competitions']);
        $this->assertSame(6, $hl['nb_matchs_comptes']);

        // 6s : le Division 3 propre domine la conversion de Mid (=> Open).
        $this->assertSame('Division 3', $this->levelOf($levels, '6s')['division_label']);
    }

    public function test_les_playoffs_comptent_pour_le_tier_superieur(): void
    {
        $now = time();
        $results = [];
        // Saison régulière en Mid (3 matchs) puis phase finale "Mid Playoffs"
        // (compétition séparée, division absente, division lue dans le nom).
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('Highlander', 100, 'Mid', team: 10, time: $now - 200 + $i,
                competitionName: 'Highlander Season 36 (Autumn 2026)');
        }
        $results[] = $this->apiResult('Highlander', 101, null, team: 10, time: $now - 100,
            competitionName: 'Highlander Season 36 (Autumn 2026): Mid Playoffs', round: 'Semi-Finals');

        $levels = $this->service->levelsFromResults($results);

        // Match de phase finale en Mid => expérience du tier au-dessus : High.
        $hl = $this->levelOf($levels, '9v9');
        $this->assertSame('High', $hl['division_label']);
        $this->assertSame(4, $hl['nb_matchs_comptes']);
    }

    public function test_les_rounds_de_playoffs_des_vieilles_saisons_comptent_aussi(): void
    {
        $now = time();
        $results = [];
        // Ancienne époque : playoffs dans la même compétition, round explicite.
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('6v6', 100, 'Division 4', team: 10, time: $now - 200 + $i);
        }
        $results[] = $this->apiResult('6v6', 100, 'Division 4', team: 10, time: $now - 100, round: 'Playoffs: Round 1');

        $levels = $this->service->levelsFromResults($results);

        // D4 en phase finale => expérience Division 3.
        $this->assertSame('Division 3', $this->levelOf($levels, '6s')['division_label']);
    }

    public function test_decay_d_une_division_par_deux_annees_pleines(): void
    {
        $now = time();
        $results = [];
        // Saison Mid terminée il y a 5 ans : deux pas de decay => Low
        // (progression High -> Mid -> Low de la règle 2.7).
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('Highlander', 100, 'Mid', team: 10, time: $now - 5 * 365 * 86400 + $i);
        }
        // Saison Open récente : ne décroît pas (tier le plus bas).
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('Highlander', 101, 'Open', team: 10, time: $now - 100 + $i);
        }

        $levels = $this->service->levelsFromResults($results);

        $hl = $this->levelOf($levels, '9v9');
        // Mid il y a 5 ans décroît en Low ; Open reste Open : le meilleur des
        // deux est Low.
        $this->assertSame('Low', $hl['division_label']);
        // Conversion officielle : 9v9 Division 5 -> 6v6 Division 6 (Open).
        $this->assertSame('Open', $this->levelOf($levels, '6s')['division_label']);
    }

    public function test_le_decay_est_plancher_a_low_division_5(): void
    {
        $now = time();
        $results = [];
        // Premiership il y a 20 ans : 10 pas de decay, plafonné à D5 => Low.
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('Highlander', 100, 'Premiership', team: 10, time: $now - 20 * 365 * 86400 + $i);
        }

        $levels = $this->service->levelsFromResults($results);

        $this->assertSame('Low', $this->levelOf($levels, '9v9')['division_label']);
    }

    public function test_conversion_croisiee_exemple_officiel_1(): void
    {
        $now = time();
        $results = [];
        // Exemple règle 2.7 : "HL Mid, 6s Low, HL Mid" => HL Mid et 6s Low.
        foreach ([
            100 => ['Highlander', 'Mid', 300],
            101 => ['6v6', 'Low', 200],
            102 => ['Highlander', 'Mid', 100],
        ] as $compId => [$type, $division, $age]) {
            for ($i = 0; $i < 3; $i++) {
                $results[] = $this->apiResult($type, $compId, $division, team: 10, time: $now - $age + $i);
            }
        }

        $levels = $this->service->levelsFromResults($results);

        $this->assertSame('Mid', $this->levelOf($levels, '9v9')['division_label']);
        // 6s : le Low propre bat la conversion de Mid (=> Open).
        $this->assertSame('Low', $this->levelOf($levels, '6s')['division_label']);
    }

    public function test_conversion_croisiee_exemple_officiel_2(): void
    {
        $now = time();
        $results = [];
        // Exemple règle 2.7 : "HL D2, 6s Low, HL Mid" => HL D2 (affiché High :
        // D1/D2 => High chez nous) et 6s D4.
        foreach ([
            100 => ['Highlander', 'Division 2', 300],
            101 => ['6v6', 'Low', 200],
            102 => ['Highlander', 'Mid', 100],
        ] as $compId => [$type, $division, $age]) {
            for ($i = 0; $i < 3; $i++) {
                $results[] = $this->apiResult($type, $compId, $division, team: 10, time: $now - $age + $i);
            }
        }

        $levels = $this->service->levelsFromResults($results);

        $this->assertSame('High', $this->levelOf($levels, '9v9')['division_label']);
        // 6s D4 : la conversion de HL D2 (=> D4) bat le Low propre (D5).
        $this->assertSame('Division 4', $this->levelOf($levels, '6s')['division_label']);
    }

    public function test_conversion_croisiee_exemple_officiel_3(): void
    {
        $now = time();
        $results = [];
        // Exemple règle 2.7 : "HL Open, 6s Mid, 6s Mid" => HL Low et 6s Mid
        // (6s Mid lu comme Division 4, borne conservatrice D3/D4).
        foreach ([
            100 => ['Highlander', 'Open', 300],
            101 => ['6v6', 'Mid', 200],
            102 => ['6v6', 'Mid', 100],
        ] as $compId => [$type, $division, $age]) {
            for ($i = 0; $i < 3; $i++) {
                $results[] = $this->apiResult($type, $compId, $division, team: 10, time: $now - $age + $i);
            }
        }

        $levels = $this->service->levelsFromResults($results);

        // HL : la conversion de 6s Mid (D4 => D5/Low) bat le Open propre.
        $this->assertSame('Low', $this->levelOf($levels, '9v9')['division_label']);
        // 6s : Mid (D4) bat la conversion de HL Open (=> Open).
        $this->assertSame('Division 4', $this->levelOf($levels, '6s')['division_label']);
    }

    public function test_les_tiers_les_plus_bas_comptent_toutes_saisons(): void
    {
        $now = time();
        $results = [];
        // Fenêtre remplie par 3 saisons 6v6 récentes...
        foreach ([100, 101, 102] as $compId) {
            for ($i = 0; $i < 3; $i++) {
                $results[] = $this->apiResult('6v6', $compId, 'Low', team: 10, time: $now - 100 + $i);
            }
        }
        // ...et une vieille saison HL Open (hors fenêtre, 10 ans) : tier le
        // plus bas, les saisons de toute l'histoire comptent (règle 2.7).
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('Highlander', 200, 'Open', team: 10, time: $now - 10 * 365 * 86400 + $i);
        }

        $levels = $this->service->levelsFromResults($results);

        $hl = $this->levelOf($levels, '9v9');
        // La conversion du 6s Low (=> Low) domine le Open historique.
        $this->assertSame('Low', $hl['division_label']);
        // Mais la vieille saison Open a bien contribué (compteurs non nuls).
        $this->assertSame(3, $hl['nb_matchs_comptes']);
        $this->assertSame(1, $hl['nb_competitions']);
    }

    public function test_le_tier_api_est_un_repli_le_nom_gagne(): void
    {
        $now = time();
        $results = [];
        // Nom inconnu mais tier valide (ex. "Round Robin Group E") : compté
        // via le tier API.
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('Highlander', 100, 'Round Robin Group E', team: 10, time: $now - 100 + $i, tier: 0);
        }

        $levels = $this->service->levelsFromResults($results);
        $this->assertSame('Premiership', $this->levelOf($levels, '9v9')['division_label']);

        // Cas réel API : "Fresh" avec tier 5 : le nom prime sur le tier
        // (sinon Fresh serait compté Low).
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('6v6', 101, 'Fresh', team: 10, time: $now - 100 + $i, tier: 5);
        }

        $levels = $this->service->levelsFromResults($results);
        $this->assertSame('Fresh', $this->levelOf($levels, '6s')['division_label']);
    }

    public function test_les_vieilles_divisions_sont_normalisees(): void
    {
        $now = time();
        $results = [];
        // Vieille échelle HL : Division 5 => Low.
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('Highlander', 100, 'Division 5D', team: 10, time: $now - 100 + $i);
        }

        $this->assertSame('Low', $this->levelOf($this->service->levelsFromResults($results), '9v9')['division_label']);

        $results = [];
        // Vieille échelle HL : Division 6 => Open ; et 6v6 : Division 6 => Open.
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('Highlander', 101, 'Division 6M', team: 10, time: $now - 100 + $i);
        }

        $this->assertSame('Open', $this->levelOf($this->service->levelsFromResults($results), '9v9')['division_label']);

        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('6v6', 102, 'Division 6J', team: 10, time: $now - 100 + $i);
        }

        $this->assertSame('Open', $this->levelOf($this->service->levelsFromResults($results), '6s')['division_label']);
    }

    public function test_le_dernier_match_pris_en_compte_est_stocke(): void
    {
        $now = time();
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->apiResult('Highlander', 100, 'Open A', team: 10, time: $now - 100 + $i);
        }

        $levels = $this->service->levelsFromResults($results);

        $this->assertSame($now - 98, $this->levelOf($levels, '9v9')['last_match_time']);
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
        ?int $tier = null,
        ?string $competitionName = null,
        string $round = 'Week 1',
    ): array {
        return [
            'clan1' => ['id' => $team, 'was_in_team' => true],
            'clan2' => ['id' => $team + 1000, 'was_in_team' => false],
            'competition' => [
                'id' => $competitionId,
                'name' => $competitionName ?? 'Comp '.$competitionId,
                'type' => $type,
                'category' => $category,
            ],
            'defaultwin' => $defaultwin,
            // Tier absent par défaut : force la normalisation du nom.
            'division' => $division === null ? null : ['id' => 1, 'name' => $division, 'tier' => $tier],
            'r1' => 6,
            'r2' => 0,
            'round' => $round,
            'time' => $time,
            'week' => 1,
        ];
    }

    /**
     * Ligne de niveau d'un mode donné.
     *
     * @param  array<int, array<string, mixed>>  $levels
     */
    private function levelOf(array $levels, string $gameMode): array
    {
        foreach ($levels as $level) {
            if ($level['game_mode'] === $gameMode) {
                return $level;
            }
        }

        $this->fail("Aucun niveau calculé pour le mode {$gameMode}.");
    }
}
