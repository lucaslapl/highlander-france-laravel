<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SeriesRepository;
use App\Services\SeriesScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Outil admin « Overlay Scores » : accès strictement réservé aux admins,
 * création d'une série depuis un lien de match ETF2L (préremplissage des
 * équipes, avatars et rosters, titre généré, repli saisie manuelle),
 * point de map manuel, annulation d'un événement, gestion des maps
 * (ajout / retrait), renommage des équipes (nom + avatar par URL),
 * rafraîchissement des rosters, resynchronisation depuis le match ETF2L
 * et suppression.
 */
class SeriesAdminTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaa';

    private const MATCH_ID = 93233;

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
     * Saisie de création cohérente avec seedSeries() : lien de match ETF2L
     * + équipes préremplies (le JS de préremplissage poste ces champs).
     *
     * @return array<string, mixed>
     */
    private function createInput(): array
    {
        return [
            'match_url' => 'https://etf2l.org/matches/'.self::MATCH_ID.'/',
            'format' => 'bo3',
            'red_name' => 'LB',
            'blue_name' => 'E6',
            'maps' => ['pl_upward_f10', 'cp_steel_f12'],
        ];
    }

    // ─── Accès ────────────────────────────────────────────────────────────

    public function test_le_panel_series_est_reserve_aux_admins(): void
    {
        $this->get('/admin/series')->assertForbidden();
        $this->get('/admin/series/etf2l-match?match_url='.urlencode('https://etf2l.org/matches/93233/'))->assertForbidden();
        $this->post('/admin/series/create', $this->createInput())->assertForbidden();
        $this->get('/admin/series/'.self::TOKEN)->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/teams', ['red_name' => 'LB', 'blue_name' => 'E6'])->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/resync')->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/point', ['map' => 'pl_upward_f10', 'team' => 'red'])->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/end', ['map' => 'cp_granary_pro_rc4'])->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/map', ['map' => 'koth_product_final'])->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/map/delete', ['map' => 'pl_upward_f10'])->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/void', ['event' => 'e1'])->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/delete')->assertForbidden();
    }

    public function test_un_token_de_serie_inconnu_renvoie_404(): void
    {
        $this->withSession($this->adminSession())->get('/admin/series/'.self::TOKEN)->assertNotFound();
    }

    // ─── Préremplissage depuis le lien de match ETF2L ─────────────────────

    public function test_l_endpoint_etf2l_match_sert_le_match_pour_le_prelissage(): void
    {
        $this->seedEtf2lMatchCache();

        $response = $this->withSession($this->adminSession())
            ->get('/admin/series/etf2l-match?match_url='.urlencode('https://etf2l.org/matches/'.self::MATCH_ID.'/'))
            ->assertOk()
            ->assertJsonStructure(['match']);

        $match = $response->json('match');
        $this->assertNotNull($match);
        $this->assertSame(self::MATCH_ID, $match['id']);
        $this->assertSame('9v9', $match['category']);
        $this->assertSame('Les Baguettes', $match['teams'][0]['name']);
        $this->assertSame(['pl_upward_f10', 'koth_product_final'], $match['maps']);
        $this->assertSame('Highlander Season 36: High Playoffs — Les Baguettes vs Escouade 6', $match['title']);
    }

    public function test_l_endpoint_etf2l_match_rejette_un_lien_invalide(): void
    {
        $response = $this->withSession($this->adminSession())
            ->get('/admin/series/etf2l-match?match_url='.urlencode('https://etf2l.org/team/21747'))
            ->assertOk();

        $this->assertNull($response->json('match'));
        $this->assertNotNull($response->json('error'));
    }

    public function test_l_endpoint_etf2l_match_signale_une_api_indisponible(): void
    {
        // Cache négatif (comme l'écrit le service sur un échec réseau) :
        // aucun appel HTTP en test, message explicite côté client.
        $this->seedEtf2lMatchNegativeCache();

        $response = $this->withSession($this->adminSession())
            ->get('/admin/series/etf2l-match?match_url='.urlencode('https://etf2l.org/matches/'.self::MATCH_ID.'/'))
            ->assertOk();

        $this->assertNull($response->json('match'));
        $this->assertNotNull($response->json('error'));
    }

    // ─── Création ─────────────────────────────────────────────────────────

    public function test_creation_d_une_serie_valide_depuis_un_match_etf2l(): void
    {
        $this->seedEtf2lMatchCache();

        $response = $this->withSession($this->adminSession())
            ->post('/admin/series/create', $this->createInput());

        $response->assertRedirect();

        $seriesList = (new SeriesRepository)->all();
        $this->assertCount(1, $seriesList);

        $token = (string) $seriesList[0]['token'];
        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $token);
        $series = (new SeriesRepository)->find($token);
        $this->assertNotNull($series);

        // Titre généré depuis le match, lien mémorisé pour la
        // resynchronisation, catégorie du match pour le menu des maps.
        $this->assertSame('Highlander Season 36: High Playoffs — Les Baguettes vs Escouade 6', $series['title']);
        $this->assertSame(self::MATCH_ID, $series['etf2l_match_id']);
        $this->assertSame('9v9', $series['category']);
        $this->assertSame(2, $series['wins_needed']);

        $this->assertSame(['name' => 'LB', 'avatar_url' => null, 'etf2l_id' => null, 'etf2l_name' => null], $series['teams']['red']);
        $this->assertSame(['name' => 'E6', 'avatar_url' => null, 'etf2l_id' => null, 'etf2l_name' => null], $series['teams']['blue']);

        // pl_upward : double attaque ; steel : double attaque (A/D connu).
        $this->assertSame('double', $series['maps'][0]['mode']);
        $this->assertSame('double', $series['maps'][1]['mode']);
    }

    public function test_la_creation_stocke_la_liaison_etf2l_des_equipes(): void
    {
        // Préremplissage : le match pousse l'identifiant ETF2L de chaque
        // équipe en champ caché, la série le mémorise (avec le nom complet
        // ETF2L en référence) pour rafraîchir les rosters ou resynchroniser.
        $this->seedEtf2lMatchCache();
        $input = $this->createInput();
        $input['red_etf2l_id'] = '21747';
        $input['blue_etf2l_id'] = '21748';

        $this->withSession($this->adminSession())
            ->post('/admin/series/create', $input)
            ->assertRedirect();

        $seriesList = (new SeriesRepository)->all();
        $series = (new SeriesRepository)->find((string) $seriesList[0]['token']);

        $this->assertSame(21747, $series['teams']['red']['etf2l_id']);
        $this->assertSame(21748, $series['teams']['blue']['etf2l_id']);
        $this->assertSame('Les Baguettes', $series['teams']['red']['etf2l_name']);
        $this->assertSame('Escouade 6', $series['teams']['blue']['etf2l_name']);
    }

    public function test_la_creation_rejette_un_lien_de_match_invalide(): void
    {
        $input = $this->createInput();
        $input['match_url'] = 'https://etf2l.org/team/21747';

        $this->withSession($this->adminSession())
            ->post('/admin/series/create', $input)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame([], (new SeriesRepository)->all());
    }

    public function test_la_creation_est_possible_sans_api_avec_un_titre_de_repli(): void
    {
        // API indisponible (cache négatif) : la série reste créable en
        // saisie manuelle, le titre replie sur l'identifiant du match.
        $this->seedEtf2lMatchNegativeCache();

        $this->withSession($this->adminSession())
            ->post('/admin/series/create', $this->createInput())
            ->assertRedirect();

        $seriesList = (new SeriesRepository)->all();
        $this->assertCount(1, $seriesList);
        $series = (new SeriesRepository)->find((string) $seriesList[0]['token']);
        $this->assertSame('Match ETF2L #'.self::MATCH_ID, $series['title']);
        $this->assertNull($series['category']);
    }

    public function test_creation_rejette_une_liste_de_maps_vide(): void
    {
        $this->seedEtf2lMatchCache();
        $input = $this->createInput();
        $input['maps'] = [];

        $this->withSession($this->adminSession())
            ->post('/admin/series/create', $input)
            ->assertRedirect();

        $this->assertSame([], (new SeriesRepository)->all());
    }

    public function test_la_creation_enregistre_les_avatars_par_url(): void
    {
        $this->seedEtf2lMatchCache();
        $input = $this->createInput();
        $input['red_avatar_url'] = 'https://example.com/lb.png';
        $input['blue_avatar_url'] = 'https://example.com/e6.png';

        $this->withSession($this->adminSession())
            ->post('/admin/series/create', $input)
            ->assertRedirect();

        $seriesList = (new SeriesRepository)->all();
        $series = (new SeriesRepository)->find((string) $seriesList[0]['token']);
        $this->assertSame('https://example.com/lb.png', $series['teams']['red']['avatar_url']);
        $this->assertSame('https://example.com/e6.png', $series['teams']['blue']['avatar_url']);
    }

    public function test_la_page_de_creation_affiche_le_lien_et_le_menu_des_maps(): void
    {
        $this->withSession($this->adminSession())
            ->get('/admin/series')
            ->assertOk()
            ->assertSee('Lien du match ETF2L')
            ->assertSee('series-load-match')
            ->assertSee('series-map-select')
            ->assertSee('admin_series_match.js')
            // Plus de remplissage assisté sur cet outil : les équipes viennent
            // du lien de match ETF2L.
            ->assertDontSee('Remplissage assisté');
    }

    // ─── Équipes ──────────────────────────────────────────────────────────

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
        $this->assertSame('bo3', $series['format']);
        $this->assertSame([], $series['journal']);
    }

    public function test_le_renommage_met_a_jour_les_avatars(): void
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

    // ─── Fin de map à timelimit (« Match terminé ») ────────────────────────

    public function test_la_fin_de_map_donne_la_map_a_l_equipe_en_tete(): void
    {
        // 5cp (winlimit 5, timelimit 30) : 3-2 au timelimit, le meneur l'emporte.
        $this->seedSeries(['cp_granary_pro_rc4']);
        $this->point('cp_granary_pro_rc4', 'red');
        $this->point('cp_granary_pro_rc4', 'red');
        $this->point('cp_granary_pro_rc4', 'red');
        $this->point('cp_granary_pro_rc4', 'blue');
        $this->point('cp_granary_pro_rc4', 'blue');

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/end', ['map' => 'cp_granary_pro_rc4'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $state = (new SeriesScoreService)->compute((new SeriesRepository)->find(self::TOKEN));
        $this->assertSame('decided', $state['maps'][0]['status']);
        $this->assertSame('red', $state['maps'][0]['winner']);
        $this->assertFalse($state['maps'][0]['golden_cap']);
        // Victoire au timelimit : 3 points de ligue (barème ETF2L).
        $this->assertSame(3, $state['score']['red']);
    }

    public function test_la_fin_de_map_a_egalite_declenche_une_golden_cap(): void
    {
        // 2-2 au timelimit : golden cap, que le point suivant tranche.
        $this->seedSeries(['cp_granary_pro_rc4']);
        $this->point('cp_granary_pro_rc4', 'red');
        $this->point('cp_granary_pro_rc4', 'red');
        $this->point('cp_granary_pro_rc4', 'blue');
        $this->point('cp_granary_pro_rc4', 'blue');

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/end', ['map' => 'cp_granary_pro_rc4'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ongoing = (new SeriesScoreService)->compute((new SeriesRepository)->find(self::TOKEN));
        $this->assertSame('pending', $ongoing['maps'][0]['status']);
        $this->assertTrue($ongoing['maps'][0]['golden_cap']);

        // Le point de golden cap décide la map : 2 points au vainqueur,
        // 1 au perdant (barème ETF2L).
        $this->point('cp_granary_pro_rc4', 'blue');

        $state = (new SeriesScoreService)->compute((new SeriesRepository)->find(self::TOKEN));
        $this->assertSame('decided', $state['maps'][0]['status']);
        $this->assertSame('blue', $state['maps'][0]['winner']);
        $this->assertSame(2, $state['score']['blue']);
        $this->assertSame(1, $state['score']['red']);
    }

    public function test_la_fin_de_map_est_refusee_sur_une_map_a_winlimit(): void
    {
        // KOTH (winlimit 3, sans timelimit) : la map ne peut pas finir
        // sans gagnant, le bouton ne la concerne pas.
        $this->seedSeries(['koth_product_final']);

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/end', ['map' => 'koth_product_final'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame([], $series['journal']);
    }

    public function test_la_fin_de_map_est_refusee_sur_une_map_double_attaque(): void
    {
        $this->seedSeries(['pl_upward_f10']);

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/end', ['map' => 'pl_upward_f10'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame([], $series['journal']);
    }

    public function test_la_fin_de_map_est_refusee_sur_une_map_deja_decidee(): void
    {
        $this->seedSeries(['cp_granary_pro_rc4']);
        $this->point('cp_granary_pro_rc4', 'red');
        $this->end('cp_granary_pro_rc4');

        // Une deuxième fin de map ne double pas l'événement.
        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/end', ['map' => 'cp_granary_pro_rc4'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_la_fin_de_map_est_refusee_quand_la_golden_cap_est_en_attente(): void
    {
        $this->seedSeries(['cp_granary_pro_rc4']);
        $this->point('cp_granary_pro_rc4', 'red');
        $this->point('cp_granary_pro_rc4', 'blue');
        $this->end('cp_granary_pro_rc4');

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/end', ['map' => 'cp_granary_pro_rc4'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_annuler_une_fin_de_map_retire_son_effet(): void
    {
        $this->seedSeries(['cp_granary_pro_rc4']);
        $this->point('cp_granary_pro_rc4', 'red');
        $this->point('cp_granary_pro_rc4', 'blue');
        $this->end('cp_granary_pro_rc4');

        // La fin de map est l'événement e3 : l'annuler rouvre la map.
        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/void', ['event' => 'e3'])
            ->assertRedirect();

        $state = (new SeriesScoreService)->compute((new SeriesRepository)->find(self::TOKEN));
        $this->assertSame('pending', $state['maps'][0]['status']);
        $this->assertFalse($state['maps'][0]['golden_cap']);
    }

    /**
     * Enregistre un point manuel pour une équipe sur une map de la série.
     */
    private function point(string $map, string $team): void
    {
        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/point', ['map' => $map, 'team' => $team])
            ->assertRedirect();
    }

    /**
     * Déclare la fin d'une map (« Match terminé »).
     */
    private function end(string $map): void
    {
        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/end', ['map' => $map])
            ->assertRedirect();
    }

    // ─── Resynchronisation depuis le match ETF2L ───────────────────────────

    public function test_la_resynchronisation_maj_equipes_maps_et_format(): void
    {
        $this->seedSeries();
        $repository = new SeriesRepository;
        $series = $repository->find(self::TOKEN);
        $series['etf2l_match_id'] = self::MATCH_ID;
        $repository->save($series);

        $this->seedEtf2lMatchCache();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/resync')
            ->assertRedirect()
            ->assertSessionHas('success');

        $series = (new SeriesRepository)->find(self::TOKEN);

        // Titre régénéré, noms complets ETF2L et liaisons à jour (le nom
        // affiché est le nom complet : l'acronyme se règle à la main).
        $this->assertSame('Highlander Season 36: High Playoffs — Les Baguettes vs Escouade 6', $series['title']);
        $this->assertSame('Les Baguettes', $series['teams']['red']['name']);
        $this->assertSame('Les Baguettes', $series['teams']['red']['etf2l_name']);
        $this->assertSame(21747, $series['teams']['red']['etf2l_id']);
        $this->assertSame('https://example.com/lb.png', $series['teams']['red']['avatar_url']);

        // Maps : seules les inconnues sont ajoutées, jamais de retrait —
        // cp_steel_f12 (déclaré à la main) reste, le score acquis tient.
        $names = array_column($series['maps'], 'name');
        $this->assertContains('pl_upward_f10', $names);
        $this->assertContains('koth_product_final', $names);
        $this->assertContains('cp_steel_f12', $names);

        // Format détecté du nombre de maps du match : deux maps → classique.
        $this->assertSame('fixed', $series['format']);
        $this->assertNull($series['wins_needed']);
    }

    public function test_la_resynchronisation_detecte_un_bo3_depuis_trois_maps(): void
    {
        $this->seedSeries();
        $repository = new SeriesRepository;
        $series = $repository->find(self::TOKEN);
        $series['etf2l_match_id'] = 93234;
        $repository->save($series);

        $this->seedEtf2lMatchCache(93234, ['pl_upward_f10', 'koth_product_final', 'cp_granary_pro_rc4']);

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/resync')
            ->assertRedirect()
            ->assertSessionHas('success');

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame('bo3', $series['format']);
        $this->assertSame(2, $series['wins_needed']);
    }

    public function test_la_resynchronisation_refuse_sans_liaison_de_match(): void
    {
        // Série manuelle (pas de lien ETF2L mémorisé) : rien à resynchroniser.
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/resync')
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_la_resynchronisation_signale_une_api_indisponible(): void
    {
        $this->seedSeries();
        $repository = new SeriesRepository;
        $series = $repository->find(self::TOKEN);
        $series['etf2l_match_id'] = self::MATCH_ID;
        $repository->save($series);

        $this->seedEtf2lMatchNegativeCache();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/resync')
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // ─── Maps de la série ──────────────────────────────────────────────────

    public function test_une_map_peut_etre_ajoutee_apres_creation(): void
    {
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/map', ['map' => 'koth_product_final'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $series = (new SeriesRepository)->find(self::TOKEN);
        $names = array_column($series['maps'], 'name');
        $this->assertContains('koth_product_final', $names);
        // Mode déduit à l'ajout, comme à la création.
        $added = null;
        foreach ($series['maps'] as $map) {
            if ($map['name'] === 'koth_product_final') {
                $added = $map;
            }
        }
        $this->assertNotNull($added);
        $this->assertSame('single', $added['mode']);
    }

    public function test_l_ajout_d_une_map_deja_connue_est_refuse(): void
    {
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/map', ['map' => 'pl_upward_f10'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertCount(2, $series['maps']);
    }

    public function test_une_map_sans_evenement_peut_etre_retiree(): void
    {
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/map/delete', ['map' => 'cp_steel_f12'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertCount(1, $series['maps']);
        $this->assertSame('pl_upward_f10', $series['maps'][0]['name']);
    }

    public function test_une_map_qui_a_compte_ne_peut_pas_etre_retiree(): void
    {
        $this->seedSeries();
        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/point', ['map' => 'pl_upward_f10', 'team' => 'red'])
            ->assertRedirect();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/map/delete', ['map' => 'pl_upward_f10'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertCount(2, $series['maps']);
    }

    // ─── Points manuels et journal ────────────────────────────────────────

    public function test_des_points_manuels_decident_une_map_koth_au_winlimit(): void
    {
        // KOTH, mp_winlimit 3 : un point marqué en direct n'emporte pas la
        // map — la première équipe à 3 points la gagne.
        $this->seedSeries(['koth_product_final']);

        foreach ([1, 2] as $i) {
            $this->withSession($this->adminSession())
                ->post('/admin/series/'.self::TOKEN.'/point', [
                    'map' => 'koth_product_final',
                    'team' => 'blue',
                    'note' => 'scoring live depuis l\'admin',
                ])
                ->assertRedirect();
        }

        $ongoing = (new SeriesScoreService)->compute((new SeriesRepository)->find(self::TOKEN));
        $this->assertSame('pending', $ongoing['maps'][0]['status']);
        $this->assertSame(2, $ongoing['maps'][0]['scores']['blue']);

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/point', [
                'map' => 'koth_product_final',
                'team' => 'blue',
                'note' => 'point validé à la main',
            ])
            ->assertRedirect();

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertCount(3, $series['journal']);
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

    // ─── Vue détail et suppression ────────────────────────────────────────

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
            ->assertSee('Ajouter la map')
            ->assertDontSee('Remplissage assisté')
            ->assertDontSee('Intervertir les équipes');
    }

    public function test_la_vue_detail_propose_la_resynchronisation_avec_liaison(): void
    {
        $this->seedSeries();
        $repository = new SeriesRepository;
        $series = $repository->find(self::TOKEN);
        $series['etf2l_match_id'] = self::MATCH_ID;
        $repository->save($series);

        $this->withSession($this->adminSession())
            ->get('/admin/series/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Resynchroniser depuis ETF2L')
            ->assertSee('Match ETF2L #'.self::MATCH_ID);

        // Sans liaison : le bouton disparaît.
        $this->seedSeries();
        $this->withSession($this->adminSession())
            ->get('/admin/series/'.self::TOKEN)
            ->assertOk()
            ->assertDontSee('Resynchroniser depuis ETF2L');
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
     * Série « à venir » prête à être pilotée.
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
            'created_at' => time() - 60,
            'teams' => [
                'red' => ['name' => 'Les Baguettes'],
                'blue' => ['name' => 'Escouade 6'],
            ],
            'maps' => $declared,
            'journal' => [],
        ]);
    }

    /**
     * Amorce le cache ETF2L d'un match (endpoint /matches/{id}, lu par le
     * préremplissage et la resynchronisation) : aucun appel HTTP en test.
     *
     * @param  array<int, string>|null  $maps
     */
    private function seedEtf2lMatchCache(int $matchId = self::MATCH_ID, ?array $maps = null): void
    {
        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api-v2.etf2l.org/matches/'.$matchId,
            'payload' => json_encode([
                'status' => ['code' => 200],
                'match' => [
                    'id' => $matchId,
                    'clan1' => [
                        'id' => 21747,
                        'name' => 'Les Baguettes',
                        'country' => 'France',
                        'steam' => ['avatar' => 'https://example.com/lb.png'],
                    ],
                    'clan2' => [
                        'id' => 21748,
                        'name' => 'Escouade 6',
                        'country' => 'France',
                        'steam' => ['avatar' => 'https://example.com/e6.png'],
                    ],
                    'competition' => ['id' => 1060, 'name' => 'Highlander Season 36: High Playoffs', 'type' => 'Highlander'],
                    'maps' => $maps ?? ['pl_upward_f10', 'koth_product_final'],
                ],
            ], JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ]);
    }

    /**
     * Amorce un cache négatif pour le match (comme le service l'écrit sur un
     * échec réseau) : le service répond « indisponible » sans appel HTTP.
     */
    private function seedEtf2lMatchNegativeCache(): void
    {
        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api-v2.etf2l.org/matches/'.self::MATCH_ID,
            'payload' => json_encode(['error' => 'hlfr_etf2l_indisponible'], JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ]);
    }
}
