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
        $this->get('/series-overlay/'.self::TOKEN.'/match')->assertNotFound();
        $this->get('/series-overlay/'.self::TOKEN.'/avatar/red')->assertNotFound();
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
        $this->assertStringContainsString('series-scoreboard__score-value">1', $html);

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

    public function test_le_delai_stv_masque_un_log_trop_recent(): void
    {
        // Cast sur SourceTV retardée (?delay=90) : un log rattaché à l'instant
        // (fin réelle de la map sur le serveur) ne doit pas s'afficher avant
        // que le flux STV retardé ne l'ait montré.
        $this->seedSeries();
        (new SeriesRepository)->appendEvent(self::TOKEN, [
            'type' => 'log',
            'source' => 'logstf',
            'log_id' => 424242,
            'map' => 'koth_product_final',
            'winner' => 'blue',
            'scores' => ['red' => 1, 'blue' => 3],
            'title' => 'HLFR : match',
        ]);

        // Sans délai : la map est décidée pour l'équipe bleue.
        $html = (string) $this->get('/series-overlay/'.self::TOKEN)->assertOk()->getContent();
        $this->assertStringContainsString('series-map--decided', $html);
        $this->assertStringContainsString('series-map__winner">Escouade 6', $html);

        // Avec le délai STV : le log est masqué, la map reste en attente.
        $html = (string) $this->get('/series-overlay/'.self::TOKEN.'?delay=90')->assertOk()->getContent();
        $this->assertStringNotContainsString('series-map--decided', $html);
        $this->assertStringNotContainsString('series-map__winner', $html);
    }

    public function test_les_points_manuels_passent_au_travers_du_delai_stv(): void
    {
        // Les points manuels sont saisis par un humain synchronisé sur le
        // flux diffusé : ils s'affichent immédiatement, délai STV ou non.
        $this->seedSeries();
        (new SeriesRepository)->appendEvent(self::TOKEN, [
            'type' => 'manual',
            'source' => 'manual',
            'map' => 'koth_product_final',
            'team' => 'red',
            'note' => '',
        ]);

        $html = (string) $this->get('/series-overlay/'.self::TOKEN.'?delay=90')->assertOk()->getContent();

        $this->assertStringContainsString('series-map__score">1 – 0', $html);
    }

    public function test_le_delai_stv_masque_les_stats_de_l_overlay_match(): void
    {
        // Les stats de la map terminée sont embarquées dans l'événement du
        // journal : l'overlay retardé sert celles du dernier log *visible*,
        // pas celles du dernier log rattaché.
        $this->seedSeries();
        (new SeriesRepository)->appendEvent(self::TOKEN, [
            'type' => 'log',
            'source' => 'logstf',
            'log_id' => 4242,
            'map' => 'koth_product_final',
            'winner' => 'red',
            'scores' => ['red' => 3, 'blue' => 1],
            'title' => 'HLFR : match',
            'stats' => $this->matchStats(),
        ]);

        // Sans délai : stats de la map terminée.
        $html = (string) $this->get('/series-overlay/'.self::TOKEN.'/match')->assertOk()->getContent();
        $this->assertStringContainsString('Joueur Rouge', $html);

        // Avec le délai STV : le log est encore masqué, repli 0-0 sans stats.
        $html = (string) $this->get('/series-overlay/'.self::TOKEN.'/match?delay=90')->assertOk()->getContent();
        $this->assertStringNotContainsString('Joueur Rouge', $html);
        $this->assertStringContainsString('overlay-team__score--red">0', $html);
    }

    public function test_la_version_retardee_reflete_l_etat_visible_et_pas_le_compteur(): void
    {
        // Avec un délai STV, la version est une empreinte de l'état visible :
        // un log rattaché mais masqué donne une version différente de la vue
        // directe — l'overlay retardé ne se rechargera qu'au moment où le log
        // devient visible, sans écriture nouvelle sur la série.
        $this->seedSeries();
        (new SeriesRepository)->appendEvent(self::TOKEN, [
            'type' => 'log',
            'source' => 'logstf',
            'log_id' => 424242,
            'map' => 'koth_product_final',
            'winner' => 'blue',
            'scores' => ['red' => 1, 'blue' => 3],
            'title' => 'HLFR : match',
        ]);

        $live = $this->get('/series-overlay/'.self::TOKEN.'/version')->assertOk()->json('version');
        $stv = $this->get('/series-overlay/'.self::TOKEN.'/version?delay=90')->assertOk()->json('version');

        $this->assertIsInt($stv);
        $this->assertNotSame($live, $stv);
    }

    public function test_des_points_manuels_comptent_le_score_live_d_une_map_koth(): void
    {
        // Scoring live d'un KOTH (mp_winlimit 3) par points manuels : le
        // score s'affiche au fil du match, la map n'est décidée qu'à 3
        // points — le score final du log la remplace en fin de map.
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

    public function test_l_overlay_de_stats_affiche_les_stats_de_la_derniere_map(): void
    {
        // Flux du réconciliateur sur une série d'avant l'embarquement des
        // stats dans le journal : log rattaché au journal, payload « stats »
        // persisté à part — le repli le sert au rendu.
        $this->seedSeries();
        (new SeriesRepository)->appendEvent(self::TOKEN, [
            'type' => 'log',
            'source' => 'logstf',
            'log_id' => 4242,
            'map' => 'koth_product_final',
            'winner' => 'red',
            'scores' => ['red' => 3, 'blue' => 1],
            'title' => 'HLFR : match',
        ]);
        $this->seedStats($this->matchStats());

        $html = (string) $this->get('/series-overlay/'.self::TOKEN.'/match')->assertOk()->getContent();

        // Noms d'équipes de la série, jamais les RED/BLU génériques du log.
        $this->assertStringContainsString('Les Baguettes', $html);
        $this->assertStringContainsString('Escouade 6', $html);
        $this->assertStringNotContainsString('>RED<', $html);

        // Score du log et joueur de la map terminée.
        $this->assertStringContainsString('overlay-team__score--red">3', $html);
        $this->assertStringContainsString('Joueur Rouge', $html);

        // Nom de map raccourci et polling sur l'endpoint de version de série.
        $this->assertStringContainsString('Product', $html);
        $this->assertStringContainsString('data-version-url="/series-overlay/'.self::TOKEN.'/version"', $html);
    }

    public function test_l_overlay_de_stats_sans_log_affiche_les_equipes_a_zero(): void
    {
        // Avant le premier log rattaché : page transparente avec les équipes
        // à 0-0, la source OBS peut être ajoutée en amont du stream.
        $this->seedSeries();

        $html = (string) $this->get('/series-overlay/'.self::TOKEN.'/match')->assertOk()->getContent();

        $this->assertStringContainsString('Les Baguettes', $html);
        $this->assertStringContainsString('Escouade 6', $html);
        $this->assertStringContainsString('overlay-team__score--red">0', $html);
        $this->assertStringContainsString('overlay-team__score--blue">0', $html);
    }

    public function test_l_avatar_d_une_equipe_sans_upload_renvoie_404(): void
    {
        $this->seedSeries();
        $this->get('/series-overlay/'.self::TOKEN.'/avatar/red')->assertNotFound();
        $this->get('/series-overlay/'.self::TOKEN.'/avatar/blue')->assertNotFound();
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

    /**
     * Écrit un payload « stats » dans la série (comme le ferait le
     * réconciliateur logs.tf après un log rattaché).
     *
     * @param  array<string, mixed>  $stats
     */
    private function seedStats(array $stats): void
    {
        $repo = new SeriesRepository;
        $series = $repo->find(self::TOKEN);
        $series['stats'] = $stats;
        $repo->save($series);
    }

    /**
     * Payload de stats au format OverlayStatsService::buildPayload().
     *
     * @return array<string, mixed>
     */
    private function matchStats(): array
    {
        return [
            'log_id' => 4242,
            'title' => 'HLFR : Les Baguettes vs Escouade 6',
            'map' => 'koth_product_final',
            'date' => time() - 120,
            'length' => 1800,
            'teams' => [
                'red' => ['name' => 'RED', 'score' => 3],
                'blue' => ['name' => 'BLU', 'score' => 1],
            ],
            'players' => [
                'red' => [[
                    'steamid3' => '[U:1:11101]',
                    'name' => 'Joueur Rouge',
                    'class' => 'soldier',
                    'kills' => 10,
                    'assists' => 2,
                    'deaths' => 3,
                    'dmg' => 4000,
                    'dapm' => 400,
                    'hr' => 0,
                    'dt' => 900,
                    'kd' => 3.33,
                    'best' => [],
                ]],
                'blue' => [],
            ],
            'medics' => [
                'red' => ['heal' => 9000, 'ubers' => 4, 'drops' => 0, 'avg_uber_length' => 6.5, 'count' => 1],
                'blue' => ['heal' => 0, 'ubers' => 0, 'drops' => 0, 'avg_uber_length' => null, 'count' => 0],
            ],
        ];
    }
}
