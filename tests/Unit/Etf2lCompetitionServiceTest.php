<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Etf2lCompetitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Import des compétitions ETF2L (API v2) pour l'overlay bracket / classement :
 * normalisation des résultats et tables, groupement des rounds en colonnes,
 * voies upper/lower/final, cache par URL. Fetcher HTTP injecté : aucun
 * réseau réel, seulement la base de test.
 */
class Etf2lCompetitionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fetcher factice : réponse par fragment d'URL, compteur d'appels.
     *
     * @param  array<string, array|null>  $responses  fragment d'URL => payload (null = erreur réseau)
     * @param  int  &$calls  compteur d'appels
     */
    private function service(array $responses, ?int &$calls = null): Etf2lCompetitionService
    {
        $calls = 0;
        $counter = &$calls;

        $fetcher = static function (string $url) use ($responses, &$counter): ?array {
            $counter++;

            foreach ($responses as $fragment => $payload) {
                if (str_contains($url, $fragment)) {
                    return $payload;
                }
            }

            return null;
        };

        return new Etf2lCompetitionService($fetcher);
    }

    private function resultsPayload(): array
    {
        return [
            'results' => ['data' => [
                [
                    'clan1' => ['name' => 'DD14', 'country' => 'France', 'steam' => ['avatar' => 'https://etf2l.org/a.png']],
                    'clan2' => ['name' => 'AKATSUKI', 'country' => 'DominicanRepublic', 'steam' => ['avatar' => null]],
                    'r1' => 6, 'r2' => 0, 'round' => 'Bye', 'time' => 1000, 'week' => 7,
                ],
                [
                    'clan1' => ['name' => 'DD14', 'country' => 'France', 'steam' => []],
                    'clan2' => ['name' => ':pregnant_man:', 'country' => 'Germany', 'steam' => []],
                    'r1' => 5, 'r2' => 1, 'round' => 'Upper Bracket Final', 'time' => 2000, 'week' => 8,
                ],
                [
                    'clan1' => ['name' => 'AKATSUKI', 'country' => 'DominicanRepublic', 'steam' => []],
                    'clan2' => ['name' => 'ЭТО МОЁ БОЛОТО', 'country' => 'Russia', 'steam' => []],
                    'r1' => 6, 'r2' => 0, 'round' => 'Lower Bracket Semi-Final', 'time' => 3000, 'week' => 9,
                ],
                [
                    'clan1' => ['name' => 'DD14', 'country' => 'France', 'steam' => []],
                    'clan2' => ['name' => 'AKATSUKI', 'country' => 'DominicanRepublic', 'steam' => []],
                    'r1' => null, 'r2' => null, 'round' => 'Grand Final', 'time' => 4000, 'week' => 10,
                ],
            ]],
            'status' => ['code' => 200],
        ];
    }

    public function test_les_resultats_sont_normalises_avec_scores_en_chaine(): void
    {
        $service = $this->service(['results' => $this->resultsPayload()]);
        $results = $service->results(1050);

        $this->assertCount(4, $results);
        $this->assertSame('Upper Bracket Final', $results[1]['round']);
        $this->assertSame('DD14', $results[1]['teams'][0]['name']);
        $this->assertSame('https://etf2l.org/a.png', $results[0]['teams'][0]['avatar']);
        $this->assertSame('6', $results[0]['teams'][0]['score']);
        $this->assertSame('0', $results[0]['teams'][1]['score']);
        $this->assertSame('', $results[3]['teams'][0]['score']);
        $this->assertSame('', $results[3]['teams'][1]['score']);
    }

    public function test_les_rounds_deviennent_des_colonnes_par_voie_et_par_ordre(): void
    {
        $service = $this->service(['results' => $this->resultsPayload()]);
        $columns = Etf2lCompetitionService::bracketColumns($service->results(1050));

        $this->assertSame('Bye', $columns[0]['label']);
        $this->assertSame('upper', $columns[0]['lane']);

        $this->assertSame('Lower Bracket Semi-Final', $columns[2]['label']);
        $this->assertSame('lower', $columns[2]['lane']);

        $this->assertSame('Grand Final', $columns[3]['label']);
        $this->assertSame('final', $columns[3]['lane']);
        $this->assertSame('', $columns[3]['matches'][0]['teams'][0]['score']);
    }

    public function test_la_voie_d_un_round_est_deduite_de_son_nom(): void
    {
        $this->assertSame('upper', Etf2lCompetitionService::roundLane('Upper Bracket Final'));
        $this->assertSame('upper', Etf2lCompetitionService::roundLane('Quarter Final'));
        $this->assertSame('lower', Etf2lCompetitionService::roundLane('Lower Bracket Semi-Final'));
        $this->assertSame('final', Etf2lCompetitionService::roundLane('Grand Final'));
        $this->assertSame('final', Etf2lCompetitionService::roundLane('Final'));
    }

    public function test_les_tables_sont_normalisees_par_division(): void
    {
        $payload = [
            'tables' => [
                'High' => [
                    ['name' => 'DD14', 'country' => 'France', 'maps_played' => 10, 'maps_won' => 9, 'maps_lost' => 1, 'score' => 27, 'penalty_points' => 0],
                    ['name' => 'AKATSUKI', 'country' => 'DominicanRepublic', 'maps_played' => 10, 'maps_won' => 7, 'maps_lost' => 3, 'score' => 21, 'penalty_points' => 2],
                ],
            ],
            'status' => ['code' => 200],
        ];

        $service = $this->service(['tables' => $payload]);
        $tables = $service->tables(1050);

        $this->assertArrayHasKey('High', $tables);
        $this->assertSame('DD14', $tables['High'][0]['name']);
        $this->assertSame(9, $tables['High'][0]['won']);
        $this->assertSame(1, $tables['High'][0]['lost']);
        $this->assertSame(27, $tables['High'][0]['score']);
        $this->assertSame(2, $tables['High'][1]['penalty']);
    }

    public function test_la_liste_ne_garde_que_les_competitions_highlander(): void
    {
        $payload = [
            'competitions' => ['data' => [
                ['id' => 1050, 'name' => 'Highlander Season 36: High', 'category' => 'Highlander Season', 'type' => 'Highlander', 'archived' => false],
                ['id' => 1053, 'name' => '6v6 Season 53', 'category' => '6v6 Season', 'type' => '6v6', 'archived' => false],
                ['id' => 1049, 'name' => 'Highlander Season 36: Premiership Qualifiers', 'category' => 'Highlander Season', 'type' => 'Highlander', 'archived' => true],
            ]],
            'status' => ['code' => 200],
        ];

        $service = $this->service(['competition/list' => $payload]);
        $competitions = $service->competitions();

        $this->assertCount(2, $competitions);
        $this->assertSame(1050, $competitions[0]['id']);
        $this->assertSame(1049, $competitions[1]['id']);
        $this->assertTrue($competitions[1]['archived']);
    }

    public function test_le_cache_evite_un_second_appel_et_le_force_le_contourne(): void
    {
        $calls = 0;
        $service = $this->service(['results' => $this->resultsPayload()], $calls);
        $service->results(1050);
        $service->results(1050);
        $this->assertSame(1, $calls);

        $service->results(1050, true);
        $this->assertSame(2, $calls);
    }
}
