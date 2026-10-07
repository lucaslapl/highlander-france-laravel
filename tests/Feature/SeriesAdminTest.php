<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OverlayRepository;
use App\Models\SeriesRepository;
use App\Services\SeriesScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Outil admin « Overlay Scores » : accès strictement réservé aux admins,
 * création d'une série (rosters SteamIDs, maps, format), lancement du suivi,
 * point de map manuel, annulation d'un événement, renommage des équipes
 * (nom + avatar par URL, mémorisés comme pour l'outil Overlay Logs) et
 * suppression.
 */
class SeriesAdminTest extends TestCase
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
        foreach (glob($this->dataDir.'/overlays/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataDir.'/overlays');
        @unlink($this->dataDir.'/series_reconcile.lock');
        @rmdir($this->dataDir);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function adminSession(): array
    {
        return ['steamid' => '76561198012345678', 'is_admin' => true];
    }

    /**
     * @return array<string, string>
     */
    private function createInput(): array
    {
        return [
            'title' => 'Finale playoffs — Les Baguettes vs Escouade 6',
            'format' => 'bo3',
            'red_name' => 'Les Baguettes',
            'blue_name' => 'Escouade 6',
            'red_players' => "76561198000000001\nSTEAM_1:1:12345",
            'blue_players' => "76561198000000011\n[U:1:98765]",
            'maps' => "pl_upward_f10\ncp_steel_f12",
        ];
    }

    // ─── Accès ────────────────────────────────────────────────────────────

    public function test_le_panel_series_est_reserve_aux_admins(): void
    {
        $this->get('/admin/series')->assertForbidden();
        $this->post('/admin/series/create', $this->createInput())->assertForbidden();
        $this->get('/admin/series/'.self::TOKEN)->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/status', ['status' => 'live'])->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/teams', ['red_name' => 'LB', 'blue_name' => 'E6'])->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/point', ['map' => 'pl_upward_f10', 'team' => 'red'])->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/void', ['event' => 'e1'])->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/delete')->assertForbidden();
    }

    public function test_un_token_de_serie_inconnu_renvoie_404(): void
    {
        $this->withSession($this->adminSession())->get('/admin/series/'.self::TOKEN)->assertNotFound();
    }

    // ─── Création ─────────────────────────────────────────────────────────

    public function test_creation_d_une_serie_valide(): void
    {
        $response = $this->withSession($this->adminSession())
            ->post('/admin/series/create', $this->createInput());

        $response->assertRedirect();

        $seriesList = (new SeriesRepository)->all();
        $this->assertCount(1, $seriesList);

        $token = (string) $seriesList[0]['token'];
        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $token);
        $series = (new SeriesRepository)->find($token);
        $this->assertNotNull($series);
        $this->assertSame('Finale playoffs — Les Baguettes vs Escouade 6', $series['title']);
        $this->assertSame('upcoming', $series['status']);
        $this->assertSame(2, $series['wins_needed']);
        $this->assertSame(['name' => 'Les Baguettes', 'players' => ['76561198000000001', '76561197960290419'], 'avatar_url' => null], $series['teams']['red']);
        $this->assertSame(['name' => 'Escouade 6', 'players' => ['76561198000000011', '76561197960364493'], 'avatar_url' => null], $series['teams']['blue']);
        // pl_upward : double attaque ; steel : double attaque (A/D connu).
        $this->assertSame('double', $series['maps'][0]['mode']);
        $this->assertSame('double', $series['maps'][1]['mode']);
    }

    public function test_creation_rejette_un_steamid_invalide(): void
    {
        $input = $this->createInput();
        $input['red_players'] = "76561198000000001\npas-un-steamid";

        $this->withSession($this->adminSession())
            ->post('/admin/series/create', $input)
            ->assertRedirect();

        $this->assertSame([], (new SeriesRepository)->all());
    }

    public function test_creation_accepte_un_mode_explicite_par_map(): void
    {
        $input = $this->createInput();
        $input['maps'] = "koth_product_final single\ncp_sunshine double";

        $this->withSession($this->adminSession())
            ->post('/admin/series/create', $input)
            ->assertRedirect();

        $seriesList = (new SeriesRepository)->all();
        $token = (string) ($seriesList[0]['token'] ?? '');
        $series = (new SeriesRepository)->find($token);

        $this->assertSame('single', $series['maps'][0]['mode']);
        $this->assertSame('double', $series['maps'][1]['mode']);
    }

    // ─── Suivi et ajustements manuels ─────────────────────────────────────

    public function test_le_lancement_du_suivi_figure_l_horloge_de_reference(): void
    {
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/status', ['status' => 'live'])
            ->assertRedirect();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame('live', $series['status']);
        $this->assertNotNull($series['started_at']);
        $this->assertGreaterThanOrEqual(time() - 10, (int) $series['started_at']);
    }

    public function test_les_equipes_peuvent_etre_renommees_apres_creation(): void
    {
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/teams', [
                'red_name' => 'LB',
                'blue_name' => 'E6',
            ])
            ->assertRedirect();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame('LB', $series['teams']['red']['name']);
        $this->assertSame('E6', $series['teams']['blue']['name']);
        // Le reste de la série est intact.
        $this->assertSame(['76561198000000001', '76561198000000002'], $series['teams']['red']['players']);
        $this->assertSame('bo3', $series['format']);
        $this->assertSame([], $series['journal']);
    }

    public function test_le_renommage_met_a_jour_les_avatars_et_memorise_les_equipes(): void
    {
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/teams', [
                'red_name' => 'LB',
                'blue_name' => 'E6',
                'red_avatar_url' => 'https://example.com/lb.png',
                'blue_avatar_url' => 'https://example.com/e6.png',
            ])
            ->assertRedirect();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame('https://example.com/lb.png', $series['teams']['red']['avatar_url']);
        $this->assertSame('https://example.com/e6.png', $series['teams']['blue']['avatar_url']);

        // Les deux équipes sont mémorisées (visuel + nom) pour les prochaines créations.
        $memorized = (new OverlayRepository)->memorizedAvatars();
        $this->assertSame(['url' => 'https://example.com/lb.png', 'name' => 'LB'], $memorized[1]);
        $this->assertSame(['url' => 'https://example.com/e6.png', 'name' => 'E6'], $memorized[0]);
    }

    public function test_la_creation_memorise_les_equipes_avec_avatar(): void
    {
        $input = $this->createInput();
        $input['red_avatar_url'] = 'https://example.com/lb.png';
        $input['blue_avatar_url'] = 'https://example.com/e6.png';

        $this->withSession($this->adminSession())
            ->post('/admin/series/create', $input)
            ->assertRedirect();

        $memorized = (new OverlayRepository)->memorizedAvatars();
        $this->assertSame(['url' => 'https://example.com/lb.png', 'name' => 'Les Baguettes'], $memorized[1]);
        $this->assertSame(['url' => 'https://example.com/e6.png', 'name' => 'Escouade 6'], $memorized[0]);
    }

    public function test_les_pages_serie_proposent_les_equipes_memorisees_en_un_clic(): void
    {
        (new OverlayRepository)->rememberAvatar('https://example.com/ig.png', 'IG');

        $this->withSession($this->adminSession())
            ->get('/admin/series')
            ->assertOk()
            ->assertSee('Équipes déjà castées')
            ->assertSee('https://example.com/ig.png');

        $this->seedSeries();
        $this->withSession($this->adminSession())
            ->get('/admin/series/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Équipes déjà castées')
            ->assertSee('https://example.com/ig.png');
    }

    public function test_le_renommage_des_equipes_rejette_un_nom_vide(): void
    {
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/teams', [
                'red_name' => '',
                'blue_name' => 'E6',
            ])
            ->assertRedirect();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame('Les Baguettes', $series['teams']['red']['name']);
        $this->assertSame('Escouade 6', $series['teams']['blue']['name']);
    }

    public function test_un_point_de_map_manuel_decide_une_map_unique(): void
    {
        $this->seedSeries(['koth_product_final']);

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/point', [
                'map' => 'koth_product_final',
                'team' => 'blue',
                'note' => 'log manquant, validé à la main',
            ])
            ->assertRedirect();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertCount(1, $series['journal']);
        $this->assertSame('manual', $series['journal'][0]['type']);

        $state = (new SeriesScoreService)->compute($series);
        $this->assertSame('decided', $state['maps'][0]['status']);
        $this->assertSame('blue', $state['maps'][0]['winner']);
    }

    public function test_un_point_de_map_sur_map_inconnue_est_refuse(): void
    {
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/point', [
                'map' => 'pl_badwater',
                'team' => 'red',
            ])
            ->assertRedirect();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame([], $series['journal']);
    }

    public function test_annuler_un_evenement_retire_son_effet_du_calcul(): void
    {
        $this->seedSeries(['koth_product_final']);

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/point', ['map' => 'koth_product_final', 'team' => 'red'])
            ->assertRedirect();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/void', ['event' => 'e1'])
            ->assertRedirect();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertCount(2, $series['journal']);
        $this->assertSame('void', $series['journal'][1]['type']);

        $state = (new SeriesScoreService)->compute($series);
        $this->assertSame('pending', $state['maps'][0]['status']);
        $this->assertSame(['red' => 0, 'blue' => 0], $state['score']);
    }

    public function test_la_vue_detail_affiche_serie_score_et_journal(): void
    {
        $this->seedSeries(['koth_product_final']);
        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/point', ['map' => 'koth_product_final', 'team' => 'red']);

        $this->withSession($this->adminSession())
            ->get('/admin/series/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Finale playoffs — Les Baguettes vs Escouade 6')
            ->assertSee('Les Baguettes')
            ->assertSee('Escouade 6')
            ->assertSee('Point manuel')
            ->assertSee('Lancer le suivi');
    }

    public function test_suppression_d_une_serie(): void
    {
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/delete')
            ->assertRedirect();

        $this->assertNull((new SeriesRepository)->find(self::TOKEN));
        $this->assertSame([], (new SeriesRepository)->all());
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────

    /**
     * Série « à venir » prête à être pilotée, avec deux rosters et un point
     * de départ en SteamID64 cohérent avec createInput().
     *
     * @param  array<int, string>|null  $maps
     */
    private function seedSeries(?array $maps = null): void
    {
        $declared = [];
        foreach ($maps ?? ['pl_upward_f10', 'cp_steel_f12'] as $name) {
            $declared[] = ['name' => $name, 'mode' => SeriesScoreService::deriveMode($name)];
        }

        (new SeriesRepository)->save([
            'token' => self::TOKEN,
            'title' => 'Finale playoffs — Les Baguettes vs Escouade 6',
            'format' => 'bo3',
            'wins_needed' => 2,
            'status' => 'upcoming',
            'started_at' => null,
            'created_at' => time() - 60,
            'teams' => [
                'red' => ['name' => 'Les Baguettes', 'players' => ['76561198000000001', '76561198000000002']],
                'blue' => ['name' => 'Escouade 6', 'players' => ['76561198000000011', '76561198000000012']],
            ],
            'maps' => $declared,
            'journal' => [],
            'seen_logs' => [],
        ]);
    }
}
