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
 * création d'une série (rosters SteamIDs, maps, format), lancement du suivi,
 * point de map manuel, annulation d'un événement, renommage des équipes
 * (nom + avatar par URL, remplissage assisté ETF2L comme pour les autres
 * outils overlay), interversion des équipes A / B et suppression.
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

        // Le remplissage assisté ETF2L des pages admin est servi depuis le
        // cache : amorce vide pour rester sans appel HTTP réel.
        $this->seedEtf2lCompetitionsCache();
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
        $this->post('/admin/series/'.self::TOKEN.'/rosters')->assertForbidden();
        $this->post('/admin/series/'.self::TOKEN.'/swap')->assertForbidden();
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
        $this->assertSame(['name' => 'Les Baguettes', 'players' => ['76561198000000001', '76561197960290419'], 'avatar_url' => null, 'etf2l_id' => null], $series['teams']['red']);
        $this->assertSame(['name' => 'Escouade 6', 'players' => ['76561198000000011', '76561197960364493'], 'avatar_url' => null, 'etf2l_id' => null], $series['teams']['blue']);
        // pl_upward : double attaque ; steel : double attaque (A/D connu).
        $this->assertSame('double', $series['maps'][0]['mode']);
        $this->assertSame('double', $series['maps'][1]['mode']);
    }

    public function test_la_creation_stocke_la_liaison_etf2l_des_equipes(): void
    {
        // Remplissage assisté : le menu équipe pousse l'identifiant ETF2L
        // en champ caché, la série le mémorise pour rafraîchir les
        // rosters plus tard (transferts entre saisons).
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

    public function test_la_creation_enregistre_les_avatars_par_url(): void
    {
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

    public function test_les_pages_serie_proposent_le_remplissage_assiste_etf2l(): void
    {
        // Même remplissage assisté que les autres outils overlay :
        // compétition ETF2L → équipes chargées via /admin/overlay/etf2l/teams.
        $this->seedEtf2lCompetitionsCache([
            ['id' => 42, 'type' => 'Highlander', 'name' => 'HLFR Test Cup', 'archived' => false],
        ]);

        $session = $this->adminSession();
        $this->withSession($session)
            ->get('/admin/series')
            ->assertOk()
            ->assertSee('Remplissage assisté')
            ->assertSee('HLFR Test Cup')
            ->assertSee('series-load-teams')
            ->assertDontSee('Équipes déjà castées');

        $this->seedSeries();
        $this->withSession($session)
            ->get('/admin/series/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Remplissage assisté')
            ->assertSee('HLFR Test Cup')
            ->assertSee('series-load-teams')
            ->assertDontSee('Équipes déjà castées');
    }

    public function test_l_interversion_echange_noms_et_avatars_mais_pas_les_rosters(): void
    {
        $this->seedSeries();
        $repository = new SeriesRepository;
        $series = $repository->find(self::TOKEN);
        $series['teams']['red']['avatar_url'] = 'https://example.com/lb.png';
        $series['teams']['blue']['avatar_url'] = 'https://example.com/e6.png';
        $repository->save($series);

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/swap')
            ->assertRedirect();

        $series = (new SeriesRepository)->find(self::TOKEN);
        // Noms et avatars échangés (affichage), rosters inchangés (ce sont
        // eux qui alignent les couleurs des logs sur les équipes).
        $this->assertSame('Escouade 6', $series['teams']['red']['name']);
        $this->assertSame('https://example.com/e6.png', $series['teams']['red']['avatar_url']);
        $this->assertSame(['76561198000000001', '76561198000000002'], $series['teams']['red']['players']);
        $this->assertSame('Les Baguettes', $series['teams']['blue']['name']);
        $this->assertSame('https://example.com/lb.png', $series['teams']['blue']['avatar_url']);
        $this->assertSame(['76561198000000011', '76561198000000012'], $series['teams']['blue']['players']);
    }

    // ─── Liaison ETF2L et rafraîchissement des rosters ────────────────────

    public function test_le_rafraichissement_remplace_les_rosters_depuis_etf2l(): void
    {
        $this->seedSeries();
        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/teams', [
                'red_name' => 'LB',
                'blue_name' => 'E6',
                'red_etf2l_id' => '21747',
                'blue_etf2l_id' => '21748',
            ])
            ->assertRedirect();

        // Rosters ETF2L servis depuis le cache (aucun réseau réel) : les
        // transferts de l'intersaison sont rattrapés d'un clic.
        $this->seedEtf2lTeamRosterCache(21747, ['76561198000000001', '76561198000000009', '76561198000000010']);
        $this->seedEtf2lTeamRosterCache(21748, ['76561198000000011', '76561198000000019']);

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/rosters')
            ->assertRedirect()
            ->assertSessionHas('success');

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame(['76561198000000001', '76561198000000009', '76561198000000010'], $series['teams']['red']['players']);
        $this->assertSame(['76561198000000011', '76561198000000019'], $series['teams']['blue']['players']);
    }

    public function test_le_rafraichissement_des_rosters_refuse_sans_liaison_etf2l(): void
    {
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/rosters')
            ->assertRedirect()
            ->assertSessionHas('error');

        $series = (new SeriesRepository)->find(self::TOKEN);
        $this->assertSame(['76561198000000001', '76561198000000002'], $series['teams']['red']['players']);
        $this->assertSame(['76561198000000011', '76561198000000012'], $series['teams']['blue']['players']);
    }

    public function test_le_rafraichissement_epargne_les_equipes_sans_liaison(): void
    {
        $this->seedSeries();
        $repository = new SeriesRepository;
        $series = $repository->find(self::TOKEN);
        $series['teams']['red']['etf2l_id'] = 21747;
        $repository->save($series);

        $this->seedEtf2lTeamRosterCache(21747, ['76561198000000077']);

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/rosters')
            ->assertRedirect()
            ->assertSessionHas('success');

        // Seule l'équipe liée est mise à jour, l'autre garde son roster.
        $series = $repository->find(self::TOKEN);
        $this->assertSame(['76561198000000077'], $series['teams']['red']['players']);
        $this->assertSame(['76561198000000011', '76561198000000012'], $series['teams']['blue']['players']);
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
                'note' => 'log manquant, validé à la main',
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
            ->assertSee('Lancer le suivi')
            ->assertSee('Intervertir les équipes A / B');
    }

    public function test_le_bouton_de_reconciliation_apparait_uniquement_sur_une_serie_live(): void
    {
        $this->seedSeries(['koth_product_final']);

        // Série non lancée : pas de bouton (le réconciliateur n'agit que sur les séries live).
        $this->withSession($this->adminSession())
            ->get('/admin/series/'.self::TOKEN)
            ->assertOk()
            ->assertDontSee('Chercher les logs maintenant');

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/status', ['status' => 'live'])
            ->assertRedirect();

        $this->withSession($this->adminSession())
            ->get('/admin/series/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Chercher les logs maintenant');
    }

    public function test_la_reconciliation_manuelle_renvoie_le_resume_du_service(): void
    {
        // Série non live : le réconciliateur s'arrête avant tout appel HTTP
        // vers logs.tf et renvoie son résumé en message flash.
        $this->seedSeries();

        $this->withSession($this->adminSession())
            ->post('/admin/series/'.self::TOKEN.'/reconcile')
            ->assertRedirect();

        $this->withSession($this->adminSession())
            ->get('/admin/series/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Aucune série en direct : rien à réconcilier.');
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
    /**
     * Amorce le cache ETF2L du roster d'une équipe (endpoint /team/{id},
     * lu par le rafraîchissement des rosters) : le service n'a alors
     * aucun appel HTTP à faire.
     *
     * @param  array<int, string>  $steamids64
     */
    private function seedEtf2lTeamRosterCache(int $teamId, array $steamids64): void
    {
        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api-v2.etf2l.org/team/'.$teamId,
            'payload' => json_encode([
                'status' => ['code' => 200],
                'team' => [
                    'id' => $teamId,
                    'name' => 'Équipe ETF2L '.$teamId,
                    'players' => array_map(
                        static fn (string $id64): array => ['name' => 'joueur', 'steam' => ['id64' => $id64]],
                        $steamids64
                    ),
                ],
            ], JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ]);
    }

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
