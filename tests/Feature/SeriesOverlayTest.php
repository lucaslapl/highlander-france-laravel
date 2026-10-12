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
 * de matchs publics. Les événements « log » ne sont plus produits depuis la
 * suppression de la récupération automatique, mais les journaux des séries
 * créées à cette époque restent calculés par la logique courante — d'où les
 * fixtures « log » ci-dessous (le journal n'est jamais réécrit, seule
 * l'agrégation évolue : barème de points de ligue ETF2L).
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
        $this->get('/series-overlay/'.self::TOKEN.'/avatar/red')->assertNotFound();
    }

    public function test_l_overlay_de_stats_de_match_n_existe_plus(): void
    {
        // L'overlay de stats de la dernière map jouée était alimenté
        // uniquement par les logs récupérés automatiquement : la route a
        // disparu avec eux (l'outil « Overlay Logs » reste pour ça).
        $this->seedSeries();
        $this->get('/series-overlay/'.self::TOKEN.'/match')->assertNotFound();
    }

    public function test_l_overlay_affiche_equipes_score_et_maps(): void
    {
        // Journal historique « log » : le score dérivé suit le barème
        // courant (points de ligue ETF2L), le journal n'est pas réécrit.
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

        // Score de série 3-3 en points de ligue (payload 2-0 rouge, koth
        // perdu : une map gagnée vaut 3 points, barème ETF2L).
        $this->assertStringContainsString('series-scoreboard__score-value">3', $html);

        // Maps décidées : aucune teinte d'équipe — le nom de l'équipe
        // vainqueure identifie la map remportée.
        $this->assertStringContainsString('series-map--decided', $html);
        $this->assertStringNotContainsString('series-map--won-', $html);
        $this->assertStringContainsString('series-map__winner">Les Baguettes', $html);
        $this->assertStringContainsString('series-map__winner">Escouade 6', $html);

        // Le JS d'auto-rafraîchissement porte bien le token et la version.
        $this->assertStringContainsString('overlay_series.js', $html);
        $this->assertStringContainsString('data-token="'.self::TOKEN.'"', $html);
    }

    public function test_l_endpoint_version_reflete_les_mises_a_jour_du_journal(): void
    {
        $this->seedSeries();

        $initial = $this->get('/series-overlay/'.self::TOKEN.'/version')->assertOk()->json('version');
        $this->assertIsInt($initial);

        // Un point manuel (contestation rattrapée depuis un téléphone) fait
        // évoluer l'état visible : l'empreinte change et l'overlay OBS se
        // rechargera au polling suivant.
        (new SeriesRepository)->appendEvent(self::TOKEN, [
            'type' => 'manual',
            'source' => 'manual',
            'map' => 'koth_product_final',
            'team' => 'red',
            'note' => '',
        ]);

        $updated = $this->get('/series-overlay/'.self::TOKEN.'/version')->assertOk()->json('version');
        $this->assertNotSame($initial, $updated);
    }

    public function test_l_endpoint_version_reflete_le_renommage_des_equipes(): void
    {
        // Changement cosmétique sans nouveau point : l'overlay doit quand
        // même se recharger (le nom affiché a changé).
        $this->seedSeries();

        $initial = $this->get('/series-overlay/'.self::TOKEN.'/version')->assertOk()->json('version');

        $repo = new SeriesRepository;
        $series = $repo->find(self::TOKEN);
        $series['teams']['red']['name'] = 'LB';
        $repo->save($series);

        $updated = $this->get('/series-overlay/'.self::TOKEN.'/version')->assertOk()->json('version');
        $this->assertNotSame($initial, $updated);
    }

    public function test_des_points_manuels_comptent_le_score_live_d_une_map_koth(): void
    {
        // Scoring live d'un KOTH (mp_winlimit 3) par points manuels : le
        // score s'affiche au fil du match, la map n'est décidée qu'à 3 points.
        $this->seedSeries();
        $repo = new SeriesRepository;
        foreach ([1, 2] as $i) {
            $repo->appendEvent(self::TOKEN, [
                'type' => 'manual',
                'source' => 'manual',
                'map' => 'koth_product_final',
                'team' => 'red',
                'note' => '',
            ]);
        }

        $html = (string) $this->get('/series-overlay/'.self::TOKEN)->assertOk()->getContent();

        $this->assertStringContainsString('series-map__score">2 – 0', $html);
        $this->assertStringNotContainsString('series-map--decided', $html);
        $this->assertStringNotContainsString('series-map__winner', $html);

        $repo->appendEvent(self::TOKEN, [
            'type' => 'manual',
            'source' => 'manual',
            'map' => 'koth_product_final',
            'team' => 'red',
            'note' => '',
        ]);

        $html = (string) $this->get('/series-overlay/'.self::TOKEN)->assertOk()->getContent();

        $this->assertStringContainsString('series-map__score">3 – 0', $html);
        $this->assertStringContainsString('series-map--decided', $html);
        $this->assertStringContainsString('series-map__winner">Les Baguettes', $html);
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

    public function test_l_avatar_d_une_equipe_sans_upload_renvoie_404(): void
    {
        $this->seedSeries();
        $this->get('/series-overlay/'.self::TOKEN.'/avatar/red')->assertNotFound();
        $this->get('/series-overlay/'.self::TOKEN.'/avatar/blue')->assertNotFound();
    }

    /**
     * Série avec un journal optionnel (« log » historiques ou rien).
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
        ]);
    }
}
