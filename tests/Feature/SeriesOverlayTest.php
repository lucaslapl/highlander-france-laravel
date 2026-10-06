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

    /** @var array<int, string> miniatures créées par un test, nettoyées en tearDown */
    private array $thumbFiles = [];

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

        foreach ($this->thumbFiles as $file) {
            @unlink($file);
        }
        $this->thumbFiles = [];

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

    public function test_une_map_unique_decidee_sans_score_de_log_affiche_0_0(): void
    {
        // Point manuel (contestation, log manquant) : aucune trace de score
        // de log, la ligne affiche 0-0 comme les maps à double attaque.
        $this->seedSeries();
        (new SeriesRepository)->appendEvent(self::TOKEN, [
            'type' => 'manual',
            'source' => 'manual',
            'map' => 'koth_product_final',
            'team' => 'red',
            'note' => '',
        ]);

        $html = (string) $this->get('/series-overlay/'.self::TOKEN)->assertOk()->getContent();

        $this->assertStringContainsString('series-map__score">0–0', $html);
        $this->assertStringContainsString('series-map--won-red', $html);
    }

    public function test_les_maps_avec_miniature_l_affichent_en_fond(): void
    {
        // Miniatures de test aux noms uniques (le dossier public des miniatures
        // n'est pas versionné, on ne touche pas aux vrais fichiers).
        $dir = public_path('storage/etf2l-maps/thumbnails');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Correspondance exacte, et repli par préfixe après suppression du
        // suffixe de version (« pl_zzztest_f10 » → « pl_zzztest_f12.jpg »).
        foreach (['koth_zzztest_final.jpg', 'pl_zzztest_f12.jpg'] as $name) {
            file_put_contents($dir.'/'.$name, 'thumb');
            $this->thumbFiles[] = $dir.'/'.$name;
        }

        $this->seedSeries([], [
            ['name' => 'koth_zzztest_final', 'mode' => SeriesScoreService::MODE_SINGLE],
            ['name' => 'pl_zzztest_f10', 'mode' => SeriesScoreService::MODE_DOUBLE],
        ]);

        $html = (string) $this->get('/series-overlay/'.self::TOKEN)->assertOk()->getContent();

        $this->assertStringContainsString('series-map--has-thumb', $html);
        $this->assertStringContainsString('etf2l-maps/thumbnails/koth_zzztest_final.jpg', $html);
        $this->assertStringContainsString('etf2l-maps/thumbnails/pl_zzztest_f12.jpg', $html);
    }

    public function test_une_map_sans_miniature_n_a_pas_de_fond_image(): void
    {
        // Aucune miniature ne correspond : la ligne reste sans fond image,
        // l'overlay ne casse pas si le dossier est vide.
        $this->seedSeries([], [
            ['name' => 'koth_yyytest_final', 'mode' => SeriesScoreService::MODE_SINGLE],
        ]);

        $html = (string) $this->get('/series-overlay/'.self::TOKEN)->assertOk()->getContent();

        $this->assertStringNotContainsString('series-map--has-thumb', $html);
    }

    /**
     * Série en direct avec un journal optionnel (événements « log » simplifiés).
     *
     * @param  array<string, array{map: string, winner: string}>  $journal
     * @param  array<int, array{name: string, mode: string}>|null  $maps
     */
    private function seedSeries(array $journal = [], ?array $maps = null): void
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
            'maps' => $maps ?? [
                ['name' => 'pl_upward_f10', 'mode' => SeriesScoreService::MODE_DOUBLE],
                ['name' => 'koth_product_final', 'mode' => SeriesScoreService::MODE_SINGLE],
            ],
            'journal' => $events,
            'seen_logs' => [],
        ]);
    }
}
