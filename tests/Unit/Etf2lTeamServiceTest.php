<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Etf2lTeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Équipes ETF2L (API v2) pour le remplissage assisté de l'outil overlay
 * bracket : liste des compétitions Highlander récentes, équipes d'une
 * compétition (nom, avatar, pays) avec pagination, dédoublonnage et tri
 * alphabétique, cache par URL dans etf2l_api_cache. Fetcher HTTP
 * injecté : aucun réseau réel, seulement la base de test.
 */
class Etf2lTeamServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fetcher factice : réponse par fragment d'URL, compteur d'appels.
     *
     * @param  array<string, array|null>  $responses  fragment d'URL => payload (null = erreur réseau)
     * @param  int  &$calls  compteur d'appels
     */
    private function service(array $responses, ?int &$calls = null): Etf2lTeamService
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

        return new Etf2lTeamService($fetcher, 0.0);
    }

    private function competitionsPayload(): array
    {
        return [
            'competitions' => ['data' => [
                ['id' => 1060, 'type' => 'Highlander', 'name' => 'Crit or Treat! Highlander One-Night-Cup', 'archived' => false],
                ['id' => 1053, 'type' => '6v6', 'name' => '6v6 Season 53', 'archived' => false],
                ['id' => 1050, 'type' => 'Highlander', 'name' => '  Highlander Season 36: High ', 'archived' => false],
                ['id' => 975, 'type' => 'Highlander', 'name' => 'Highlander Season 34: High Playoffs', 'archived' => true],
            ]],
            'status' => ['code' => 200],
        ];
    }

    /**
     * @param  array<int, array{name: string, avatar: ?string, country: ?string}>  $teams
     */
    private function teamsPayload(array $teams, int $lastPage = 1): array
    {
        return [
            'teams' => [
                'current_page' => 1,
                'last_page' => $lastPage,
                'data' => array_map(static fn (array $t): array => [
                    'id' => $t['name'] !== '' ? crc32($t['name']) : 0,
                    'name' => $t['name'],
                    'country' => $t['country'] ?? '',
                    'dropped' => 0,
                    'steam' => ['avatar' => $t['avatar']],
                ], $teams),
            ],
            'status' => ['code' => 200],
        ];
    }

    public function test_les_competitions_highlander_recentes_sont_listees(): void
    {
        $service = $this->service(['competition/list' => $this->competitionsPayload()]);

        $competitions = $service->competitions();

        $this->assertSame([
            ['id' => 1060, 'name' => 'Crit or Treat! Highlander One-Night-Cup', 'archived' => false],
            ['id' => 1050, 'name' => 'Highlander Season 36: High', 'archived' => false],
            ['id' => 975, 'name' => 'Highlander Season 34: High Playoffs', 'archived' => true],
        ], $competitions);
    }

    public function test_les_equipes_sont_normalisees_triees_et_dedoublonnees(): void
    {
        $page1 = $this->teamsPayload([
            ['name' => 'DD14', 'avatar' => 'https://etf2l.org/dd14.png', 'country' => 'France'],
            ['name' => '  AKATSUKI  ', 'avatar' => 'javascript:alert(1)', 'country' => 'DominicanRepublic'],
            ['name' => 'ЭТО МОЁ БОЛОТО', 'avatar' => null, 'country' => 'Russia'],
        ]);
        // Même équipe que la page 1 (même id, crc32 du nom) : ignorée.
        $page1['teams']['data'][3] = ['id' => crc32('DD14'), 'name' => 'DD14', 'country' => 'France', 'dropped' => 0, 'steam' => ['avatar' => 'https://etf2l.org/dd14.png']];

        $service = $this->service(['teams?limit=100&page=1' => $page1]);

        $teams = $service->teams(1050);

        $this->assertSame('AKATSUKI', $teams[0]['name']);
        $this->assertSame('', $teams[0]['avatar']);
        $this->assertSame('DominicanRepublic', $teams[0]['country']);
        $this->assertSame('DD14', $teams[1]['name']);
        $this->assertSame('https://etf2l.org/dd14.png', $teams[1]['avatar']);
        $this->assertSame('ЭТО МОЁ БОЛОТО', $teams[2]['name']);
        $this->assertCount(3, $teams);
    }

    public function test_la_pagination_est_suivie_jusqu_a_la_derniere_page(): void
    {
        $page1 = $this->teamsPayload([
            ['name' => 'Alpha', 'avatar' => null, 'country' => ''],
            ['name' => 'Bravo', 'avatar' => null, 'country' => ''],
        ], 2);
        $page2 = $this->teamsPayload([['name' => 'Charlie', 'avatar' => null, 'country' => '']], 2);

        $service = $this->service([
            'teams?limit=100&page=1' => $page1,
            'teams?limit=100&page=2' => $page2,
        ], $calls);

        $teams = $service->teams(1050);

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($teams, 'name'));
        $this->assertSame(2, $calls);
    }

    public function test_la_lecture_s_arrete_au_garde_fou_de_300_equipes(): void
    {
        // 2 équipes uniques par page, l'API annonce 500 pages : la lecture
        // s'arrête au garde-fou de 300 équipes (150 pages consultées).
        $calls = 0;
        $fetcher = static function (string $url) use (&$calls): ?array {
            $calls++;
            if (! preg_match('~page=(\d+)~', $url, $m)) {
                return null;
            }
            $page = (int) $m[1];

            return [
                'teams' => [
                    'last_page' => 500,
                    'data' => [
                        ['id' => $page * 2 - 1, 'name' => 'Team '.($page * 2 - 1), 'country' => '', 'dropped' => 0, 'steam' => ['avatar' => null]],
                        ['id' => $page * 2, 'name' => 'Team '.($page * 2), 'country' => '', 'dropped' => 0, 'steam' => ['avatar' => null]],
                    ],
                ],
                'status' => ['code' => 200],
            ];
        };

        $service = new Etf2lTeamService($fetcher, 0.0);

        $teams = $service->teams(1050);

        $this->assertCount(300, $teams);
        $this->assertSame(150, $calls);
    }

    public function test_une_api_indisponible_renvoie_une_liste_vide(): void
    {
        $service = $this->service(['teams' => null]);

        $this->assertSame([], $service->teams(1050));
    }

    public function test_les_reponses_sont_mises_en_cache_par_url(): void
    {
        $page1 = $this->teamsPayload([['name' => 'DD14', 'avatar' => 'https://etf2l.org/a.png', 'country' => 'France']]);

        $service = $this->service(['teams?limit=100&page=1' => $page1], $calls);

        $this->assertCount(1, $service->teams(1050));
        $this->assertCount(1, $service->teams(1050));
        $this->assertSame(1, $calls);
    }
}
