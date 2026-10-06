<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\OverlayStatsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Construction du payload d'overlay depuis une réponse de l'API logs.tf :
 * normalisation des équipes/joueurs, tri par classe, mise en avant des
 * meilleures stats et agrégats medics.
 */
class OverlayStatsServiceTest extends TestCase
{
    // ─── Fixtures (contrat /api/v1/log/{id} de logs.tf) ────────────────────

    /**
     * @return array<string, mixed>
     */
    private function logResponse(): array
    {
        return [
            'success' => true,
            'length' => 1800,
            'names' => [
                '[U:1:111]' => 'ScoutFR',
                '[U:1:222]' => 'SoldatFR',
                '[U:1:333]' => 'DocFR',
                '[U:1:444]' => 'ScoutEN',
                '[U:1:555]' => 'DocEN',
            ],
            'teams' => [
                'Red' => ['score' => 3, 'kills' => 100],
                'Blue' => ['score' => 2, 'kills' => 90],
            ],
            'info' => [
                'title' => 'Highlander France: BLU vs RED',
                'map' => 'koth_proper_fix',
                'date' => 1779390707,
            ],
            'players' => [
                '[U:1:111]' => [
                    'team' => 'Red',
                    'kills' => 30, 'assists' => 5, 'deaths' => 10,
                    'dmg' => 6000, 'dapm' => 200, 'hr' => 5000, 'dt' => 4000,
                    'class_stats' => [['type' => 'scout', 'dmg' => 6000]],
                ],
                '[U:1:222]' => [
                    'team' => 'Red',
                    'kills' => 25, 'assists' => 10, 'deaths' => 12,
                    'dmg' => 9000, 'dapm' => 300, 'hr' => 3000, 'dt' => 3000,
                    'class_stats' => [['type' => 'soldier', 'dmg' => 9000]],
                ],
                '[U:1:333]' => [
                    'team' => 'Red',
                    'kills' => 2, 'assists' => 8, 'deaths' => 8,
                    'dmg' => 500, 'dapm' => 20, 'hr' => 0, 'dt' => 5000,
                    'heal' => 35000, 'ubers' => 10, 'drops' => 1,
                    'class_stats' => [['type' => 'medic', 'dmg' => 500]],
                    'medicstats' => ['avg_uber_length' => 6.0],
                ],
                '[U:1:444]' => [
                    'team' => 'Blue',
                    'kills' => 20, 'assists' => 3, 'deaths' => 14,
                    'dmg' => 4000, 'dapm' => 150, 'hr' => 6000, 'dt' => 2500,
                    'class_stats' => [['type' => 'scout', 'dmg' => 4000]],
                ],
                '[U:1:555]' => [
                    'team' => 'Blue',
                    'kills' => 1, 'assists' => 6, 'deaths' => 9,
                    'dmg' => 100, 'dapm' => 5, 'hr' => 0, 'dt' => 4500,
                    'heal' => 20000, 'ubers' => 4, 'drops' => 0,
                    'class_stats' => [['type' => 'medic', 'dmg' => 100]],
                    'medicstats' => ['avg_uber_length' => 8.0],
                ],
            ],
        ];
    }

    /**
     * @return array{service: OverlayStatsService, fetches: array<int, int>, fetcher: callable}
     */
    private function serviceWithFixture(array $response): array
    {
        $fetches = [];

        return [
            'service' => new OverlayStatsService,
            'fetches' => &$fetches,
            'fetcher' => static function (int $id) use ($response, &$fetches): ?array {
                $fetches[] = $id;

                return $response;
            },
        ];
    }

    // ─── Payload ───────────────────────────────────────────────────────────

    public function test_construit_le_payload_depuis_un_log(): void
    {
        $fixture = $this->serviceWithFixture($this->logResponse());

        $payload = $fixture['service']->buildPayload(4059225, $fixture['fetcher']);

        $this->assertNotNull($payload);
        $this->assertSame(4059225, $payload['log_id']);
        $this->assertSame('koth_proper_fix', $payload['map']);
        $this->assertSame('Highlander France: BLU vs RED', $payload['title']);
        $this->assertSame(3, $payload['teams']['red']['score']);
        $this->assertSame(2, $payload['teams']['blue']['score']);
        $this->assertCount(3, $payload['players']['red']);
        $this->assertCount(2, $payload['players']['blue']);
    }

    public function test_trie_les_joueurs_par_ordre_de_classe(): void
    {
        $fixture = $this->serviceWithFixture($this->logResponse());
        $payload = $fixture['service']->buildPayload(1, $fixture['fetcher']);

        $classes = array_column($payload['players']['red'], 'class');

        $this->assertSame(['scout', 'soldier', 'medic'], $classes);
    }

    public function test_marque_la_meilleure_valeur_de_chaque_stat(): void
    {
        $fixture = $this->serviceWithFixture($this->logResponse());
        $payload = $fixture['service']->buildPayload(1, $fixture['fetcher']);

        $byName = [];
        foreach (['red', 'blue'] as $team) {
            foreach ($payload['players'][$team] as $player) {
                $byName[$player['name']] = $player['best'];
            }
        }

        // Meilleur kills : ScoutFR (30), meilleures assists et dmg : SoldatFR,
        // meilleur heal reçu : ScoutEN (6000), meilleur dmg pris : DocFR (5000),
        // le plus de morts : ScoutEN (14).
        $this->assertContains('kills', $byName['ScoutFR']);
        $this->assertContains('kd', $byName['ScoutFR']);
        $this->assertContains('assists', $byName['SoldatFR']);
        $this->assertContains('dmg', $byName['SoldatFR']);
        $this->assertContains('dapm', $byName['SoldatFR']);
        $this->assertContains('hr', $byName['ScoutEN']);
        $this->assertContains('deaths', $byName['ScoutEN']);
        $this->assertContains('dt', $byName['DocFR']);
    }

    public function test_agrege_les_stats_medics_par_equipe(): void
    {
        $fixture = $this->serviceWithFixture($this->logResponse());
        $payload = $fixture['service']->buildPayload(1, $fixture['fetcher']);

        $red = $payload['medics']['red'];
        $blue = $payload['medics']['blue'];

        $this->assertSame(35000, $red['heal']);
        $this->assertSame(10, $red['ubers']);
        $this->assertSame(1, $red['drops']);
        $this->assertSame(1, $red['count']);
        $this->assertEqualsWithDelta(6.0, $red['avg_uber_length'], 0.01);

        $this->assertSame(20000, $blue['heal']);
        $this->assertSame(4, $blue['ubers']);

        $this->assertEqualsWithDelta(8.0, $blue['avg_uber_length'], 0.01);
    }

    public function test_cumule_et_pondere_deux_medics_d_une_meme_equipe(): void
    {
        $response = $this->logResponse();
        // Deux medics rouges : 6 s sur 10 ubers et 10 s sur 30 ubers.
        $response['players']['[U:1:222]'] = [
            'team' => 'Red',
            'kills' => 0, 'assists' => 0, 'deaths' => 5,
            'dmg' => 100, 'dapm' => 3, 'hr' => 0, 'dt' => 1000,
            'heal' => 10000, 'ubers' => 30, 'drops' => 2,
            'class_stats' => [['type' => 'medic', 'dmg' => 100]],
            'medicstats' => ['avg_uber_length' => 10.0],
        ];

        $fixture = $this->serviceWithFixture($response);
        $payload = $fixture['service']->buildPayload(1, $fixture['fetcher']);
        $red = $payload['medics']['red'];

        $this->assertSame(45000, $red['heal']);
        $this->assertSame(40, $red['ubers']);
        $this->assertSame(3, $red['drops']);
        $this->assertSame(2, $red['count']);
        // (6*10 + 10*30) / 40 = 9 s.
        $this->assertEqualsWithDelta(9.0, $red['avg_uber_length'], 0.01);
    }

    public function test_equipe_sans_medic_renvoie_des_agregats_vides(): void
    {
        $response = $this->logResponse();
        unset($response['players']['[U:1:555]']);

        $fixture = $this->serviceWithFixture($response);
        $payload = $fixture['service']->buildPayload(1, $fixture['fetcher']);

        $this->assertSame(0, $payload['medics']['blue']['count']);
        $this->assertSame(0, $payload['medics']['blue']['heal']);
        $this->assertNull($payload['medics']['blue']['avg_uber_length']);
    }

    #[DataProvider('invalidResponsesProvider')]
    public function test_rejette_les_reponses_invalides(?array $response): void
    {
        $fixture = $this->serviceWithFixture($response ?? []);

        $this->assertNull($fixture['service']->buildPayload(1, $fixture['fetcher']));
    }

    /**
     * @return array<string, array{0: array<string, mixed>|null}>
     */
    public static function invalidResponsesProvider(): array
    {
        return [
            'échec API' => [['success' => false, 'players' => []]],
            'sans joueurs' => [['success' => true, 'players' => []]],
            'réponse nulle' => [null],
            'équipes inconnues' => [[
                'success' => true,
                'players' => ['[U:1:1]' => ['team' => 'green']],
            ]],
        ];
    }
}
