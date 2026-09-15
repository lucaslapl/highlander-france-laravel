<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PlayerStatsService;
use Tests\TestCase;

class PlayerStatsServiceTest extends TestCase
{
    private const STEAMID3 = '[U:1:52079950]';

    /**
     * Construit un payload /api/v1/log/<id> minimal (au format logs.tf) pour un
     * set de joueurs donné.
     *
     * @param  array<int, array{steamid: string, row: array<string, mixed>}>  $players
     * @return array<string, mixed>
     */
    private function logDetails(array $players, int $length = 900, int $redScore = 2, int $blueScore = 1): array
    {
        $map = [];
        foreach ($players as $entry) {
            $map[$entry['steamid']] = array_merge(['team' => 'red'], $entry['row']);
        }

        return [
            'length' => $length,
            'teams' => ['Red' => ['score' => $redScore], 'Blue' => ['score' => $blueScore]],
            'players' => $map,
            'classkills' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function playerRow(array $overrides = []): array
    {
        return array_merge([
            'team' => 'red', 'dapm' => 300, 'dmg' => 10000, 'dt' => 5000,
            'kills' => 10, 'deaths' => 4, 'assists' => 3, 'suicides' => 0,
            'heal' => 2000, 'medkits' => 2, 'medkits_hp' => 120, 'ubers' => 1,
            'drops' => 0, 'backstabs' => 0, 'headshots' => 0, 'as' => 2,
            'cpc' => 1, 'lks' => 5,
        ], $overrides);
    }

    /**
     * Service configuré pour servir des fixtures au lieu de l'API logs.tf.
     *
     * @return array{service: PlayerStatsService, fixtures: array<int, array<string, mixed>>}
     */
    private function serviceWithFixtures(array $fixtures): array
    {
        $fetcher = static fn (int $logId): ?array => $fixtures[$logId] ?? null;

        return ['service' => new PlayerStatsService($fetcher), 'fixtures' => $fixtures];
    }

    // ─── Résolution du joueur ───────────────────────────────────────────────

    public function test_resolve_player_accepte_les_formats_deja_supportes(): void
    {
        $service = new PlayerStatsService;

        $this->assertSame('[U:1:52079950]', $service->resolvePlayer('76561198012345678'));
        $this->assertSame('[U:1:52079950]', $service->resolvePlayer('https://steamcommunity.com/profiles/76561198012345678'));
        $this->assertSame('[U:1:52079950]', $service->resolvePlayer('[U:1:52079950]'));
        $this->assertSame('[U:1:52079950]', $service->resolvePlayer('U:1:52079950'));
        $this->assertSame('[U:1:52079951]', $service->resolvePlayer('STEAM_1:1:26039975'));
        $this->assertNull($service->resolvePlayer('   '));
        $this->assertNull($service->resolvePlayer('pas-un-steamid'));
    }

    public function test_parse_log_id_accepte_id_et_url(): void
    {
        $service = new PlayerStatsService;

        $this->assertSame(12345678, $service->parseLogId('12345678'));
        $this->assertSame(12345678, $service->parseLogId('https://logs.tf/12345678'));
        $this->assertSame(12345678, $service->parseLogId(' logs.tf/12345678 '));
        $this->assertNull($service->parseLogId('nope'));
        $this->assertNull($service->parseLogId('123'));
    }

    // ─── Agrégations ────────────────────────────────────────────────────────

    public function test_le_calcul_agrege_les_stats_sur_plusieurs_logs(): void
    {
        $s3 = self::STEAMID3;

        $fixtures = [
            1001 => $this->logDetails([
                ['steamid' => $s3, 'row' => $this->playerRow(['team' => 'red', 'dapm' => 300, 'dmg' => 10000, 'kills' => 10, 'deaths' => 4, 'heal' => 2000])],
            ], 900, 2, 1),
            // Red perd (won=0), dapm 200, soins 1000.
            1002 => $this->logDetails([
                ['steamid' => $s3, 'row' => $this->playerRow(['team' => 'blue', 'dapm' => 250, 'dmg' => 9000, 'kills' => 6, 'deaths' => 6, 'heal' => 1500])],
            ], 900, 1, 2),
            // Blue perd => joueur (red) gagne (won=1).
            1003 => $this->logDetails([
                ['steamid' => $s3, 'row' => $this->playerRow(['team' => 'red', 'dapm' => 200, 'dmg' => 8000, 'kills' => 5, 'deaths' => 10, 'heal' => 1000])],
            ], 900, 1, 2),
        ];

        $result = $this->serviceWithFixtures($fixtures)['service']
            ->compute($s3, array_keys($fixtures), ['kd', 'dpm', 'dmg', 'heal', 'winrate']);

        $this->assertSame(3, $result['logs_usable']);
        $this->assertSame(3, $result['logs_total']);

        $stats = $result['stats'];
        $this->assertSame(1.05, $stats['kd']);
        $this->assertSame(250, $stats['dpm']);
        $this->assertSame(27000, $stats['dmg_total']);
        $this->assertSame(9000, $stats['dmg_avg']);
        $this->assertSame(4500, $stats['heal']);
        $this->assertSame(67, $stats['winrate']);
    }

    public function test_seules_les_stats_cochees_sont_renvoyees(): void
    {
        $s3 = self::STEAMID3;

        $fixtures = [
            2001 => $this->logDetails([['steamid' => $s3, 'row' => $this->playerRow()]]),
        ];

        $result = $this->serviceWithFixtures($fixtures)['service']
            ->compute($s3, [2001], ['kd', 'dpm']);

        $this->assertSame(['kd', 'dpm'], array_keys($result['stats']));
    }

    public function test_un_log_absent_ou_sans_joueur_est_exclu_mais_traçable(): void
    {
        $s3 = self::STEAMID3;
        $other = '[U:1:999999]';

        $fixtures = [
            3001 => $this->logDetails([['steamid' => $s3, 'row' => $this->playerRow(['dmg' => 10000])]]),
            3002 => $this->logDetails([['steamid' => $other, 'row' => $this->playerRow()]]), // joueur absent
            // 3003 absent des fixtures => échec de récupération.
        ];

        $result = $this->serviceWithFixtures($fixtures)['service']
            ->compute($s3, [3001, 3002, 3003], ['kd', 'dmg']);

        $this->assertSame(1, $result['logs_usable']);
        $this->assertSame(3, $result['logs_total']);
        $this->assertSame(10000, $result['stats']['dmg_total']);
        $this->assertSame(10000, $result['stats']['dmg_avg']);

        $byId = [];
        foreach ($result['logs'] as $log) {
            $byId[$log['log_id']] = $log;
        }

        $this->assertTrue($byId[3002]['found']);
        $this->assertFalse($byId[3002]['player_present']);
        $this->assertFalse($byId[3003]['found']);
        $this->assertNotNull($byId[3003]['error']);
    }

    public function test_winrate_est_nul_sans_match_decide_et_zero_mort_en_kd(): void
    {
        $s3 = self::STEAMID3;

        // Match nul (scores égaux) : won = null, pas de décision.
        $fixtures = [
            4001 => $this->logDetails(
                [['steamid' => $s3, 'row' => $this->playerRow(['kills' => 8, 'deaths' => 0, 'dapm' => 400])]],
                900, 1, 1
            ),
        ];

        $result = $this->serviceWithFixtures($fixtures)['service']
            ->compute($s3, [4001], ['kd', 'dpm', 'winrate']);

        $this->assertSame(8.0, $result['stats']['kd']);
        $this->assertSame(400, $result['stats']['dpm']);
        $this->assertNull($result['stats']['winrate']);
    }

    public function test_les_logs_dupliques_sont_dedoublonnes(): void
    {
        $s3 = self::STEAMID3;

        $fixtures = [
            5001 => $this->logDetails([['steamid' => $s3, 'row' => $this->playerRow(['dmg' => 10000])]]),
        ];

        $result = $this->serviceWithFixtures($fixtures)['service']
            ->compute($s3, [5001, 5001, 5001], ['dmg']);

        $this->assertSame(1, $result['logs_total']);
        $this->assertSame(10000, $result['stats']['dmg_total']);
    }

    // ─── Page admin ─────────────────────────────────────────────────────────

    public function test_la_page_stats_joueur_est_reservee_aux_admins(): void
    {
        $this->get('/admin/stats-joueur')->assertForbidden();

        $this->withSession(['steamid' => '76561198012345678', 'is_admin' => true])
            ->get('/admin/stats-joueur')
            ->assertOk()
            ->assertSee('Stats joueur')
            ->assertSee('Ratio K/D')
            ->assertSee('Winrate');
    }
}
