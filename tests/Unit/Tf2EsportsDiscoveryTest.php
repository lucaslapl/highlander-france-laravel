<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Tf2EsportsApi;
use App\Services\Tf2EsportsDiscovery;
use Tests\TestCase;

class Tf2EsportsDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Nettoie le cache JSON éventuel pour exercer réellement le transport.
        foreach (glob(hlfr_data_path('tf2esports_*.json')) ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Transport déclenché sur l'URL : répond selon le chemin appelé.
     */
    private function transport(array $matches, array $rows, array $stats): \Closure
    {
        return static function (string $url) use ($matches, $rows, $stats): array {
            if (str_contains($url, '/leaderboard')) {
                $rowsSend = str_contains($url, 'format=Highlander') ? $rows : [];

                return ['data' => ['metric' => 'kd', 'rows' => $rowsSend], 'http_code' => 200, 'curl_error' => '', 'headers' => []];
            }

            if (str_contains($url, '/players/') && preg_match('#/players/(\d+)/stats#', $url, $m) === 1) {
                if (isset($stats[(int) $m[1]])) {
                    return ['data' => $stats[(int) $m[1]], 'http_code' => 200, 'curl_error' => '', 'headers' => []];
                }

                return ['data' => ['error' => 'not_found', 'message' => 'No tracked stats for this player.'], 'http_code' => 404, 'curl_error' => '', 'headers' => []];
            }

            if (str_contains($url, '/matches?')) {
                return ['data' => ['page' => 1, 'limit' => 100, 'matches' => $matches], 'http_code' => 200, 'curl_error' => '', 'headers' => []];
            }

            return ['data' => null, 'http_code' => 0, 'curl_error' => 'urlinattendue', 'headers' => []];
        };
    }

    public function test_discover_croise_les_matchs_et_collecte_les_logs_id(): void
    {
        $matches1 = [
            ['id' => '500001', 'date' => '2026-09-11', 'status' => 'Completed', 'format' => 'Highlander',
                'event_name' => 'ETF2L Highlander S36 (Autumn 2026)',
                'league_match_url' => 'https://etf2l.org/matches/93021/'],
            ['id' => '500000', 'date' => '2026-09-04', 'status' => 'Completed', 'format' => 'Highlander',
                'event_name' => 'ETF2L Highlander S36 (Autumn 2026)',
                'league_match_url' => 'https://etf2l.org/matches/93000/'],
            // Match qui ne correspond à aucun résultat ETF2L : ignoré.
            ['id' => '999888', 'date' => '2026-09-01', 'status' => 'Completed', 'format' => 'Highlander',
                'event_name' => 'pug highlanderfrance.tf', 'league_match_url' => 'https://etf2l.org/matches/111111/'],
        ];
        $rows = [
            ['player_id' => '333', 'player_name' => 'Raptor', 'team_name' => 'Inglorious Gamblers', 'kd' => '1.2'],
            ['player_id' => '334', 'player_name' => 'HorsRoster', 'team_name' => 'Inglorious Gamblers', 'kd' => '5.0'],
        ];
        $stats = [
            333 => [
                'totals' => ['player_id' => '333', 'player_name' => 'Raptor'],
                'recent' => [
                    ['match_id' => '500001', 'class_primary' => 'demoman', 'kills' => 11, 'deaths' => 4,
                        'damage' => 4534, 'dpm' => 361, 'healing' => 0, 'ubers' => 0,
                        'played_at' => '2026-09-11T14:30:10.000Z', 'logs_id' => '7009001'],
                    ['match_id' => '500000', 'class_primary' => 'demoman', 'kills' => 2, 'deaths' => 8,
                        'damage' => 900, 'dpm' => 40, 'healing' => 0, 'ubers' => 0,
                        'played_at' => '2026-09-04T14:00:00.000Z', 'logs_id' => '7009002'],
                    // Match hors équipe : ne doit pas produire de log.
                    ['match_id' => '999888', 'class_primary' => 'soldier', 'kills' => 5, 'deaths' => 5,
                        'damage' => 0, 'dpm' => 0, 'healing' => 0, 'ubers' => 0,
                        'played_at' => '2026-09-01T00:00:00.000Z', 'logs_id' => '7009003'],
                ],
            ],
        ];

        $api = new Tf2EsportsApi($this->transport($matches1, $rows, $stats));
        $results = [
            ['result' => 93021, 'time' => 1789063200, 'competition' => ['type' => 'Highlander']],
            ['result' => 93000, 'time' => 1788458400, 'competition' => ['type' => 'Highlander']],
        ];
        $players = [['name' => 'Raptor', 'role' => 'Leader', 'steamid64' => '76561198066269912']];

        $logs = (new Tf2EsportsDiscovery($api))->discoverLogs('Inglorious Gamblers', $results, $players);

        $this->assertCount(2, $logs);
        $this->assertSame([7009001, 7009002], array_values(array_map(
            static fn (array $l): int => $l['id'],
            $logs,
        )));
        $this->assertSame('9v9', $logs[0]['mode']);
        $this->assertSame('tf2esports', $logs[0]['source']);
        $this->assertSame('ETF2L Highlander S36 (Autumn 2026)', $logs[0]['title']);
    }

    public function test_discover_retourne_vide_sans_matchs_croises(): void
    {
        // Aucun match tf2esports ne croise les résultats ETF2L.
        $api = new Tf2EsportsApi($this->transport(
            [['id' => '888', 'league_match_url' => 'https://etf2l.org/matches/111111/']],
            [],
            [],
        ));
        $results = [['result' => 93021, 'time' => 1789063200, 'competition' => ['type' => 'Highlander']]];
        $players = [['name' => 'Raptor', 'role' => 'Leader', 'steamid64' => 'x']];

        $this->assertSame([], (new Tf2EsportsDiscovery($api))->discoverLogs('Inglorious Gamblers', $results, $players));
    }

    public function test_discover_retourne_vide_sans_resultats_etf2l(): void
    {
        $api = new Tf2EsportsApi($this->transport([], [], []));

        $this->assertSame([], (new Tf2EsportsDiscovery($api))->discoverLogs('IG', [], [['name' => 'Raptor']]));
    }
}
