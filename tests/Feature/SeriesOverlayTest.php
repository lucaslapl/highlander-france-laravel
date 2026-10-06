<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SeriesRepository;
use App\Services\SeriesScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Overlay OBS du scoreboard de série : page publique par token (source
 * navigateur OBS, fond transparent) et endpoint de version interrogé par la
 * page pour se rafraîchir. Aucune donnée sensible : noms d'équipes et scores
 * de matchs publics.
 */
class SeriesOverlayTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaa';

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Dossier de données dédié aux tests : rien ne pollue le vrai
        // storage/app/hlfr et tout est nettoyé en tearDown.
        $this->dataDir = storage_path('app/hlfr_series_overlay_test');
        config(['hlfr.data_dir' => $this->dataDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataDir.'/series/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataDir.'/series');
        @rmdir($this->dataDir);

        parent::tearDown();
    }

    public function test_un_token_de_serie_inconnu_renvoie_404(): void
    {
        $this->get('/series-overlay/'.self::TOKEN)->assertNotFound();
        $this->get('/series-overlay/'.self::TOKEN.'/version')->assertNotFound();
    }

    public function test_l_overlay_affiche_equipes_score_et_maps(): void
    {
        $this->seedSeries([
            'e1' => ['map' => 'pl_upward_f10', 'winner' => 'red'],
            'e2' => ['map' => 'pl_upward_f10', 'winner' => 'red'],
            'e3' => ['map' => 'koth_product_final', 'winner' => 'blue'],
        ]);

        $response = $this->get('/series-overlay/'.self::TOKEN);
        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('Les Baguettes', $html);
        $this->assertStringContainsString('Escouade 6', $html);

        // Score de série 1-1 : payload 2-0 rouge, koth perdu.
        $this->assertStringContainsString('series-scoreboard__score-value--red">1', $html);
        $this->assertStringContainsString('series-scoreboard__score-value--blue">1', $html);

        // Maps décidées avec la couleur de l'équipe vainqueure.
        $this->assertStringContainsString('series-map--won-red', $html);
        $this->assertStringContainsString('series-map--won-blue', $html);

        // Le JS d'auto-rafraîchissement porte bien le token et la version.
        $this->assertStringContainsString('overlay_series.js', $html);
        $this->assertStringContainsString('data-token="'.self::TOKEN.'"', $html);
    }

    public function test_l_endpoint_version_reflete_les_mises_a_jour_du_journal(): void
    {
        $this->seedSeries();

        $initial = $this->get('/series-overlay/'.self::TOKEN.'/version')->assertOk()->json('version');
        $this->assertIsInt($initial);

        // Un point manuel (contestation rattrapée depuis un téléphone) bump
        // la version : l'overlay OBS se rechargera au polling suivant.
        (new SeriesRepository)->appendEvent(self::TOKEN, [
            'type' => 'manual',
            'source' => 'manual',
            'map' => 'koth_product_final',
            'team' => 'red',
            'note' => '',
        ]);

        $updated = $this->get('/series-overlay/'.self::TOKEN.'/version')->assertOk()->json('version');
        $this->assertGreaterThan($initial, $updated);
    }

    /**
     * Série en direct avec un journal optionnel (événements « log » simplifiés).
     *
     * @param  array<string, array{map: string, winner: string}>  $journal
     */
    private function seedSeries(array $journal = []): void
    {
        $events = [];
        foreach ($journal as $id => $event) {
            $events[] = [
                'id' => $id,
                'type' => 'log',
                'source' => 'logstf',
                'log_id' => random_int(100000, 999999),
                'map' => $event['map'],
                'winner' => $event['winner'],
                'scores' => ['red' => 1, 'blue' => 0],
                'title' => 'HLFR : match',
                'at' => time(),
            ];
        }

        (new SeriesRepository)->save([
            'token' => self::TOKEN,
            'title' => 'Finale playoffs — Les Baguettes vs Escouade 6',
            'format' => 'bo3',
            'wins_needed' => 2,
            'status' => 'live',
            'started_at' => time() - 3600,
            'created_at' => time() - 4000,
            'version' => 1000,
            'teams' => [
                'red' => ['name' => 'Les Baguettes', 'players' => ['76561198000000001']],
                'blue' => ['name' => 'Escouade 6', 'players' => ['76561198000000011']],
            ],
            'maps' => [
                ['name' => 'pl_upward_f10', 'mode' => SeriesScoreService::MODE_DOUBLE],
                ['name' => 'koth_product_final', 'mode' => SeriesScoreService::MODE_SINGLE],
            ],
            'journal' => $events,
            'seen_logs' => [],
        ]);
    }
}
