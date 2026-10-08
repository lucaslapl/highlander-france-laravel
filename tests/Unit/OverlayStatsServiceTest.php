<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Etf2lNameResolver;
use App\Services\OverlayStatsService;
use App\Services\SteamId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Construction du payload d'overlay depuis une réponse de l'API logs.tf :
 * normalisation des équipes/joueurs, tri par classe, mise en avant des
 * meilleures stats et agrégats medics. Les pseudos ETF2L priment sur les
 * noms en jeu, avec repli sur le nom du log.
 */
class OverlayStatsServiceTest extends TestCase
{
    use RefreshDatabase;
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
        // Cache négatif ETF2L pour chaque joueur du log : la résolution des
        // pseudos reste en base (aucun appel HTTP, donc pas de rate-limit
        // usleep dans les tests) et les noms du log sont conservés.
        foreach (array_keys($response['players'] ?? []) as $steamid3) {
            DB::table('etf2l_api_cache')->insert([
                'url' => 'https://api-v2.etf2l.org/player/'.SteamId::toSteamId64((string) $steamid3),
                'payload' => json_encode(['status' => ['code' => 404, 'message' => 'No player was found']], JSON_THROW_ON_ERROR),
                'fetched_at' => time(),
            ]);
        }

        $fetches = [];

        return [
            'service' => new OverlayStatsService(new Etf2lNameResolver(static fn (string $url): ?array => null)),
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

    public function test_utilise_le_pseudo_etf2l_quand_disponible_et_le_nom_du_log_sinon(): void
    {
        // [U:1:111] (nom en jeu « ScoutFR ») figure dans les rosters ETF2L
        // synchronisés : son pseudo ETF2L doit primer. Les autres joueurs
        // gardent le nom du log (cache négatif du fixture).
        DB::table('etf2l_players')->insert([
            'team_id' => 15176,
            'player_id' => 112835,
            'name' => 'Psycho',
            'steamid64' => SteamId::toSteamId64('[U:1:111]'),
        ]);

        $fixture = $this->serviceWithFixture($this->logResponse());
        $payload = $fixture['service']->buildPayload(1, $fixture['fetcher']);

        $redNames = array_column($payload['players']['red'], 'name');
        $blueNames = array_column($payload['players']['blue'], 'name');

        $this->assertSame(['Psycho', 'SoldatFR', 'DocFR'], $redNames);
        $this->assertSame(['ScoutEN', 'DocEN'], $blueNames);
    }

    public function test_trie_les_joueurs_par_ordre_de_classe(): void
    {
        $fixture = $this->serviceWithFixture($this->logResponse());
        $payload = $fixture['service']->buildPayload(1, $fixture['fetcher']);

        $classes = array_column($payload['players']['red'], 'class');

        $this->assertSame(['scout', 'soldier', 'medic'], $classes);
    }

    public function test_marque_la_meilleure_valeur_de_chaque_stat_par_equipe(): void
    {
        $fixture = $this->serviceWithFixture($this->logResponse());
        $payload = $fixture['service']->buildPayload(1, $fixture['fetcher']);

        $bests = [];
        foreach (['red', 'blue'] as $team) {
            foreach ($payload['players'][$team] as $player) {
                $bests[$team][$player['name']] = $player['best'];
            }
        }

        // Rouge : ScoutFR domine kills/kd/hr, SoldatFR assists/dmg/dapm/deaths.
        $this->assertContains('kills', $bests['red']['ScoutFR']);
        $this->assertContains('kd', $bests['red']['ScoutFR']);
        $this->assertContains('hr', $bests['red']['ScoutFR']);
        $this->assertContains('assists', $bests['red']['SoldatFR']);
        $this->assertContains('dmg', $bests['red']['SoldatFR']);
        $this->assertContains('dapm', $bests['red']['SoldatFR']);
        $this->assertContains('deaths', $bests['red']['SoldatFR']);
        $this->assertContains('dt', $bests['red']['DocFR']);

        // Bleu : ScoutEN domine kills/deaths/dmg/dapm/hr/kd, DocEN assists/dt.
        foreach (['kills', 'deaths', 'dmg', 'dapm', 'hr', 'kd'] as $stat) {
            $this->assertContains($stat, $bests['blue']['ScoutEN']);
        }
        $this->assertContains('assists', $bests['blue']['DocEN']);
        $this->assertContains('dt', $bests['blue']['DocEN']);
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

    public function test_apply_best_stats_recalcule_un_overlay_persiste(): void
    {
        $fixture = $this->serviceWithFixture($this->logResponse());
        $overlay = $fixture['service']->buildPayload(1, $fixture['fetcher']);

        // Simule un overlay persisté avec une logique obsolète : les bests
        // globaux (une seule équipe) et non plus par équipe.
        foreach (['red', 'blue'] as $team) {
            foreach ($overlay['players'][$team] as &$player) {
                $player['best'] = $team === 'red' ? ['kills'] : [];
            }
        }
        unset($player);

        $fixture['service']->applyBestStats($overlay);

        $this->assertContains('kills', $overlay['players']['red'][0]['best']);
        $this->assertContains('hr', $overlay['players']['red'][0]['best']);
        // L'équipe bleue doit avoir ses propres bests (ici tout ScoutEN
        // sauf assists/dt qui reviennent à DocEN).
        $this->assertContains('kills', $overlay['players']['blue'][0]['best']);
        $this->assertContains('assists', $overlay['players']['blue'][1]['best']);
        $this->assertContains('dt', $overlay['players']['blue'][1]['best']);
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

    // ─── Alignement des couleurs du log sur les rosters ───────────────────

    /**
     * Payload minimal façon buildPayload : l'alignement ne regarde que
     * les steamid3 des joueurs, les scores voyagent avec les équipes.
     *
     * @return array<string, mixed>
     */
    private function alignmentPayload(): array
    {
        return [
            'teams' => [
                'red' => ['name' => 'RED', 'score' => 1],
                'blue' => ['name' => 'BLU', 'score' => 4],
            ],
            'players' => [
                'red' => [
                    ['steamid3' => '[U:1:101]', 'name' => 'R1'],
                    ['steamid3' => '[U:1:102]', 'name' => 'R2'],
                ],
                'blue' => [
                    ['steamid3' => '[U:1:201]', 'name' => 'B1'],
                    ['steamid3' => '[U:1:202]', 'name' => 'B2'],
                ],
            ],
            'medics' => [
                'red' => ['heal' => 100, 'count' => 1],
                'blue' => ['heal' => 200, 'count' => 1],
            ],
        ];
    }

    /**
     * SteamID64 équivalents des steamid3 du payload ([U:1:N] ↔ 76561197960265728+N).
     *
     * @param  array<int, string>  $steamids3
     * @return array<int, string>
     */
    private function rosterOf(array $steamids3): array
    {
        return array_map(static fn (string $id3): string => SteamId::toSteamId64($id3), $steamids3);
    }

    public function test_les_rosters_confirment_les_couleurs_du_log(): void
    {
        $payload = $this->alignmentPayload();
        $redRoster = $this->rosterOf(['[U:1:101]', '[U:1:102]']);
        $blueRoster = $this->rosterOf(['[U:1:201]', '[U:1:202]']);

        $alignment = OverlayStatsService::alignTeamsByRosters($payload, $redRoster, $blueRoster);

        $this->assertFalse($alignment['swapped']);
        $this->assertFalse($alignment['ambiguous']);
        $this->assertSame($payload, $alignment['payload']);
    }

    public function test_les_rosters_inversent_les_couleurs_du_log(): void
    {
        $payload = $this->alignmentPayload();

        // L'équipe A (roster rouge) a joué BLU sur ce log : les côtés
        // s'échangent pour que la clé red porte toujours l'équipe A.
        $redRoster = $this->rosterOf(['[U:1:201]', '[U:1:202]']);
        $blueRoster = $this->rosterOf(['[U:1:101]', '[U:1:102]']);

        $alignment = OverlayStatsService::alignTeamsByRosters($payload, $redRoster, $blueRoster);

        $this->assertTrue($alignment['swapped']);
        $this->assertFalse($alignment['ambiguous']);
        $this->assertSame('[U:1:201]', $alignment['payload']['players']['red'][0]['steamid3']);
        $this->assertSame(4, $alignment['payload']['teams']['red']['score']);
        $this->assertSame(1, $alignment['payload']['teams']['blue']['score']);
        $this->assertSame(200, $alignment['payload']['medics']['red']['heal']);
    }

    public function test_un_roster_absent_du_log_rend_l_alignement_ambigu(): void
    {
        $payload = $this->alignmentPayload();
        $redRoster = $this->rosterOf(['[U:1:901]', '[U:1:902]']);
        $blueRoster = $this->rosterOf(['[U:1:201]', '[U:1:202]']);

        $alignment = OverlayStatsService::alignTeamsByRosters($payload, $redRoster, $blueRoster);

        $this->assertFalse($alignment['swapped']);
        $this->assertTrue($alignment['ambiguous']);
        $this->assertSame($payload, $alignment['payload']);
    }

    public function test_une_couverture_a_egalite_rend_l_alignement_ambigu(): void
    {
        $payload = $this->alignmentPayload();

        // Un joueur du roster rouge de chaque côté du log : impossible
        // de trancher, le payload reste tel quel (repli manuel).
        $redRoster = $this->rosterOf(['[U:1:101]', '[U:1:201]']);
        $blueRoster = $this->rosterOf(['[U:1:102]', '[U:1:202]']);

        $alignment = OverlayStatsService::alignTeamsByRosters($payload, $redRoster, $blueRoster);

        $this->assertFalse($alignment['swapped']);
        $this->assertTrue($alignment['ambiguous']);
        $this->assertSame($payload, $alignment['payload']);
    }

    public function test_sans_roster_complet_l_alignement_est_ambigu(): void
    {
        $payload = $this->alignmentPayload();

        // Un seul roster : pas de référence, aucun échange hasardeux.
        $alignment = OverlayStatsService::alignTeamsByRosters($payload, $this->rosterOf(['[U:1:101]']), []);

        $this->assertFalse($alignment['swapped']);
        $this->assertTrue($alignment['ambiguous']);
        $this->assertSame($payload, $alignment['payload']);
    }
}
