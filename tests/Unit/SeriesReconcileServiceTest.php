<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\SeriesRepository;
use App\Services\Crons\SeriesReconcileService;
use App\Services\SeriesScoreService;
use App\Services\SteamId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Réconciliation d'une série avec logs.tf (SeriesReconcileService) : recherche
 * des logs par joueurs des rosters, filtrage (map de la série, couverture des
 * rosters, couleurs non ambiguës, date de lancement du suivi), normalisation
 * du gagnant en équipe de série, dédup des logs déjà vus et bascule
 * automatique en « finished » une fois la série décidée.
 *
 * Les appels HTTP sont injectés : aucun réseau en test.
 */
class SeriesReconcileServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaa';

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Dossier de données dédié aux tests : rien ne pollue le vrai
        // storage/app/hlfr et tout est nettoyé en tearDown.
        $this->dataDir = storage_path('app/hlfr_series_test');
        config(['hlfr.data_dir' => $this->dataDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataDir.'/series/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataDir.'/series');
        @unlink($this->dataDir.'/series_reconcile.lock');
        @rmdir($this->dataDir);

        parent::tearDown();
    }

    // ─── Cas nominaux ─────────────────────────────────────────────────────

    public function test_un_log_valide_est_rattache_a_la_serie_et_compte(): void
    {
        $this->seedSeries(['76561198000000001', '76561198000000002'], ['76561198000000011', '76561198000000012']);

        $service = new SeriesReconcileService(
            $this->searcher([101]),
            $this->fetcher([101 => $this->detail(['Red' => 1, 'Blue' => 0])])
        );

        $result = $service->run();

        $this->assertStringStartsWith('SUCCESS', $result);

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame(['101' => 'applied'], $series['seen_logs']);

        $event = $series['journal'][0] ?? [];
        $this->assertSame('log', $event['type']);
        $this->assertSame('cp_steel_f12', $event['map']);
        $this->assertSame('red', $event['winner']);
        $this->assertSame(101, $event['log_id']);

        $state = (new SeriesScoreService)->compute($series);
        $this->assertSame(1, $state['maps'][0]['rounds']['red']);
        $this->assertSame('pending', $state['maps'][0]['status']);
    }

    public function test_les_couleurs_sont_normalisees_meme_si_le_rouge_est_bleu(): void
    {
        // L'équipe « red » de la série joue BLU sur ce log (changement de
        // couleur entre deux moitiés d'un payload) : le gagnant reste exprimé
        // en clé d'équipe de série, jamais en couleur.
        $this->seedSeries(['76561198000000001', '76561198000000002'], ['76561198000000011', '76561198000000012']);

        $service = new SeriesReconcileService(
            $this->searcher([101]),
            $this->fetcher([101 => $this->detail(['Red' => 0, 'Blue' => 1], reversed: true)])
        );

        $service->run();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $event = $series['journal'][0] ?? [];

        $this->assertSame('red', $event['winner']);
        $this->assertSame(['red' => 1, 'blue' => 0], $event['scores']);
    }

    public function test_un_log_deja_vu_n_est_pas_retéléchargé(): void
    {
        $this->seedSeries(['76561198000000001', '76561198000000002'], ['76561198000000011', '76561198000000012']);

        $detailCalls = ['count' => 0];
        $fetcher = function (int $logId) use (&$detailCalls): ?array {
            $detailCalls['count']++;

            return $this->detail(['Red' => 1, 'Blue' => 0]);
        };

        $service = new SeriesReconcileService($this->searcher([101]), $fetcher);
        $service->run();
        $this->assertSame(1, $detailCalls['count']);

        // Deuxième passe : le log 101 est déjà vu, aucun appel réseau.
        $service->run();
        $this->assertSame(1, $detailCalls['count']);

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertCount(1, $series['journal']);
    }

    public function test_une_serie_decidee_passe_automatiquement_en_terminee(): void
    {
        // Format « maps fixes » avec une seule map KOTH : le log unique décide
        // la série, le statut doit basculer en « finished » sans intervention.
        $this->seedSeries(
            ['76561198000000001', '76561198000000002'],
            ['76561198000000011', '76561198000000012'],
            ['koth_product_final']
        );

        $service = new SeriesReconcileService(
            $this->searcher([101]),
            $this->fetcher([101 => $this->detail(['Red' => 4, 'Blue' => 2], map: 'koth_product_final')])
        );

        $service->run();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame('finished', $series['status']);

        $state = (new SeriesScoreService)->compute($series);
        $this->assertSame('red', $state['winner']);
        $this->assertSame(1, $state['score']['red']);
    }

    // ─── Rejets ──────────────────────────────────────────────────────────

    public function test_un_log_d_une_map_hors_serie_est_rejete_avec_raison(): void
    {
        $this->seedSeries(['76561198000000001'], ['76561198000000011']);

        $service = new SeriesReconcileService(
            $this->searcher([101]),
            $this->fetcher([101 => $this->detail(['Red' => 1, 'Blue' => 0], map: 'pl_badwater')])
        );

        $result = $service->run();

        $this->assertStringStartsWith('Rien de nouveau', $result);

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame(['101' => 'rejected:map_hors_serie'], $series['seen_logs']);
        $this->assertSame([], $series['journal']);
    }

    public function test_un_log_sans_assez_de_joueurs_du_roster_est_rejete(): void
    {
        // Roster de 6 (seuil : 6 - 3 mercs = 3) mais seulement 2 présents :
        // probablement un scrim, pas le match de la série.
        $red = ['76561198000000001', '76561198000000002', '76561198000000003', '76561198000000004', '76561198000000005', '76561198000000006'];
        $blue = ['76561198000000011', '76561198000000012', '76561198000000013', '76561198000000014', '76561198000000015', '76561198000000016'];
        $this->seedSeries($red, $blue);

        $service = new SeriesReconcileService(
            $this->searcher([101]),
            $this->fetcher([101 => $this->detail(['Red' => 1, 'Blue' => 0], presentRed: 2, presentBlue: 2)])
        );

        $service->run();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame(['101' => 'rejected:roster_insuffisant'], $series['seen_logs']);
        $this->assertSame([], $series['journal']);
    }

    public function test_un_log_egalite_est_compte_sans_gagnant(): void
    {
        $this->seedSeries(['76561198000000001', '76561198000000002'], ['76561198000000011', '76561198000000012']);

        $service = new SeriesReconcileService(
            $this->searcher([101]),
            $this->fetcher([101 => $this->detail(['Red' => 3, 'Blue' => 3], map: 'koth_product_final')])
        );

        $service->run();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame(['101' => 'applied'], $series['seen_logs']);
        $this->assertNull($series['journal'][0]['winner']);

        $state = (new SeriesScoreService)->compute($series);
        $this->assertSame('pending', $state['maps'][0]['status']);
    }

    public function test_sans_serie_en_direct_aucun_appel_reseau(): void
    {
        $calls = ['search' => 0, 'detail' => 0];
        $service = new SeriesReconcileService(
            function () use (&$calls): ?array {
                $calls['search']++;

                return [];
            },
            function () use (&$calls): ?array {
                $calls['detail']++;

                return null;
            }
        );

        $this->assertSame('Aucune série en direct : rien à réconcilier.', $service->run());
        $this->assertSame(0, $calls['search']);
        $this->assertSame(0, $calls['detail']);
    }

    public function test_un_log_anterieur_au_lancement_du_suivi_est_ignore(): void
    {
        $this->seedSeries(['76561198000000001'], ['76561198000000011']);

        $fetchCalls = ['detail' => 0];
        $service = new SeriesReconcileService(
            static function (): array {
                // Log antérieur au lancement du suivi (warmup, ancien match).
                return ['logs' => [['id' => 101, 'date' => time() - 7200, 'map' => 'cp_steel_f12', 'title' => 'HLFR']]];
            },
            function () use (&$fetchCalls): ?array {
                $fetchCalls['detail']++;

                return null;
            }
        );

        $result = $service->run();

        // Filtré dès la réponse de recherche : jamais téléchargé, jamais vu.
        $this->assertStringStartsWith('Rien de nouveau', $result);
        $this->assertSame(0, $fetchCalls['detail']);

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame([], $series['seen_logs']);
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $red
     * @param  array<int, string>  $blue
     * @param  array<int, string>  $maps
     */
    private function seedSeries(array $red, array $blue, array $maps = ['cp_steel_f12', 'koth_product_final']): void
    {
        $this->seedSeriesAt($red, $blue, $maps, self::TOKEN, time() - 600);
    }

    /**
     * @param  array<int, string>  $red
     * @param  array<int, string>  $blue
     * @param  array<int, string>  $maps
     */
    private function seedSeriesAt(array $red, array $blue, array $maps, string $token, int $startedAt): void
    {
        $declared = [];
        foreach ($maps as $name) {
            $declared[] = ['name' => $name, 'mode' => SeriesScoreService::deriveMode($name)];
        }

        (new SeriesRepository)->save([
            'token' => $token,
            'title' => 'Test série playoffs',
            'format' => 'fixed',
            'wins_needed' => null,
            'status' => 'live',
            'started_at' => $startedAt,
            'created_at' => $startedAt - 60,
            'teams' => [
                'red' => ['name' => 'Les Rouges', 'players' => $red],
                'blue' => ['name' => 'Les Bleus', 'players' => $blue],
            ],
            'maps' => $declared,
            'journal' => [],
            'seen_logs' => [],
        ]);
    }

    /**
     * Recherche logs.tf factice : renvoie les IDs donnés comme logs récents.
     *
     * @param  array<int, int>  $logIds
     */
    private function searcher(array $logIds): callable
    {
        return static function () use ($logIds): array {
            $logs = [];
            foreach ($logIds as $id) {
                $logs[] = ['id' => $id, 'date' => time() - 60, 'map' => 'cp_steel_f12', 'title' => 'HLFR : match'];
            }

            return ['logs' => $logs];
        };
    }

    /**
     * Détail logs.tf factice, mémorisé par ID de log.
     *
     * @param  array<int, array<string, mixed>>  $details
     */
    private function fetcher(array $details): callable
    {
        return function (int $logId) use ($details): ?array {
            return $details[$logId] ?? null;
        };
    }

    /**
     * Réponse du contrat /api/v1/log/{id} : joueurs des deux rosters (4 par
     * équipe par défaut), scores par couleur. En mode « reversed », l'équipe
     * « red » de la série joue Blue sur ce log.
     *
     * @param  array{Red: int, Blue: int}  $scores
     * @return array<string, mixed>
     */
    private function detail(array $scores, string $map = 'cp_steel_f12', int $presentRed = 4, int $presentBlue = 4, bool $reversed = false): array
    {
        $players = [];

        $redIds = ['76561198000000001', '76561198000000002', '76561198000000003', '76561198000000004'];
        $blueIds = ['76561198000000011', '76561198000000012', '76561198000000013', '76561198000000014'];

        $redColor = $reversed ? 'Blue' : 'Red';
        $blueColor = $reversed ? 'Red' : 'Blue';

        foreach (array_slice($redIds, 0, $presentRed) as $steamid64) {
            $players[SteamId::toSteamId3($steamid64)] = ['team' => $redColor];
        }
        foreach (array_slice($blueIds, 0, $presentBlue) as $steamid64) {
            $players[SteamId::toSteamId3($steamid64)] = ['team' => $blueColor];
        }

        return [
            'success' => true,
            'info' => ['title' => 'HLFR : Les Rouges vs Les Bleus', 'map' => $map, 'date' => time() - 60],
            'teams' => [
                'Red' => ['score' => $scores['Red']],
                'Blue' => ['score' => $scores['Blue']],
            ],
            'players' => $players,
        ];
    }
}
