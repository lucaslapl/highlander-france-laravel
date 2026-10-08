<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RostersRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Overlay OBS des rosters d'équipes : pages publiques par token (source
 * navigateur OBS, fond transparent) et endpoint de version interrogé par
 * la page pour se rafraîchir. L'outil admin (création / édition) impose le
 * format choisi à la création (Highlander = neuf classes, 6v6 = six
 * classes) : les affectations de classes suivent cet ordre, chaque joueur
 * peut être marqué merc pour le badge doré de l'overlay. Aucune donnée
 * sensible : noms d'équipes et pseudos publics des joueurs inscrits.
 */
class RostersOverlayTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaa';

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Dossier de données dédié aux tests : rien ne pollue le vrai
        // storage/app/hlfr et tout est nettoyé en tearDown.
        $this->dataDir = storage_path('app/hlfr_rosters_overlay_test');
        config(['hlfr.data_dir' => $this->dataDir]);

        // Le remplissage assisté ETF2L de l'éditeur est servi depuis le
        // cache : amorce vide pour rester sans appel HTTP réel.
        $this->seedEtf2lCompetitionsCache();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataDir.'/rosters/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataDir.'/rosters');
        @rmdir($this->dataDir);

        parent::tearDown();
    }

    public function test_un_token_inconnu_renvoie_404(): void
    {
        $this->get('/roster-overlay/'.self::TOKEN)->assertNotFound();
        $this->get('/roster-overlay/'.self::TOKEN.'/version')->assertNotFound();
    }

    public function test_l_outil_est_interdit_aux_visiteurs_et_aux_joueurs_sans_role(): void
    {
        $this->get('/admin/overlay/rosters')->assertForbidden();

        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/overlay/rosters')->assertForbidden();
    }

    public function test_un_caster_accede_a_l_outil(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/rosters')->assertOk();
    }

    public function test_la_creation_posse_les_classes_du_format(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/rosters/create', [
                'format' => 'hl',
                'eyebrow' => 'ETF2L Highlander Saison 40 - Division 1',
                'title' => 'Rosters',
            ])
            ->assertRedirect();

        $overlays = (new RostersRepository)->all();
        $this->assertCount(1, $overlays);

        $overlay = (new RostersRepository)->find((string) $overlays[0]['token']);
        $this->assertSame('hl', $overlay['format']);
        $this->assertCount(9, $overlay['teams']['a']['players']);
        $this->assertCount(9, $overlay['teams']['b']['players']);
        $this->assertSame('scout', $overlay['teams']['a']['players'][0]['class']);
        $this->assertSame('spy', $overlay['teams']['a']['players'][8]['class']);
    }

    public function test_la_creation_6v6_posse_deux_scouts_et_deux_soldiers(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/rosters/create', [
                'format' => '6v6',
                'eyebrow' => 'ETF2L 6v6 Season 53',
                'title' => 'Rosters',
            ])
            ->assertRedirect();

        $overlays = (new RostersRepository)->all();
        $overlay = (new RostersRepository)->find((string) $overlays[0]['token']);

        $this->assertSame('6v6', $overlay['format']);
        $this->assertSame(
            ['scout', 'scout', 'soldier', 'soldier', 'demoman', 'medic'],
            array_column($overlay['teams']['a']['players'], 'class')
        );
    }

    public function test_l_editeur_s_affiche_avec_les_classes_et_le_remplissage_assiste(): void
    {
        $this->seedRosters(['format' => 'hl']);

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/rosters/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Overlay Rosters')
            ->assertSee('Équipe A')
            ->assertSee('Équipe B')
            ->assertSee('Scout')
            ->assertSee('Spy')
            ->assertSee('Remplissage assisté');
    }

    public function test_l_editeur_6v6_numerote_les_classes_dupliquees(): void
    {
        $this->seedRosters(['format' => '6v6']);

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/rosters/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Scout 1')
            ->assertSee('Scout 2')
            ->assertSee('Soldier 2')
            ->assertSee('Demoman');
    }

    public function test_la_mise_a_jour_enregistre_pseudos_mercs_et_equipes(): void
    {
        $this->seedRosters(['format' => 'hl']);

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/rosters/'.self::TOKEN.'/update', [
                'eyebrow' => 'ETF2L Highlander Saison 40',
                'title' => 'Rosters',
                'team_a' => ['name' => 'DD14', 'avatar' => 'https://example.com/dd14.png', 'etf2l_id' => '21747'],
                'team_b' => ['name' => 'AKATSUKI', 'avatar' => '', 'etf2l_id' => ''],
                'players_a' => [
                    0 => ['name' => 'xine', 'merc' => '0'],
                    1 => ['name' => 'dexton123', 'merc' => '1'],
                    2 => ['name' => '', 'merc' => '0'],
                ],
                'players_b' => [
                    0 => ['name' => 'kaylus', 'merc' => '1'],
                ],
            ])
            ->assertSessionHas('success');

        $overlay = $this->findOverlay();
        $this->assertSame('DD14', $overlay['teams']['a']['name']);
        $this->assertSame('https://example.com/dd14.png', $overlay['teams']['a']['avatar']);
        $this->assertSame(21747, $overlay['teams']['a']['etf2l_id']);
        $this->assertNull($overlay['teams']['b']['etf2l_id']);

        // Les classes viennent du format, pas du formulaire : les slots
        // manquants restent vides et les entrées surnuméraires ignorées.
        $this->assertSame('scout', $overlay['teams']['a']['players'][0]['class']);
        $this->assertSame('xine', $overlay['teams']['a']['players'][0]['name']);
        $this->assertFalse($overlay['teams']['a']['players'][0]['merc']);
        $this->assertSame('soldier', $overlay['teams']['a']['players'][1]['class']);
        $this->assertSame('dexton123', $overlay['teams']['a']['players'][1]['name']);
        $this->assertTrue($overlay['teams']['a']['players'][1]['merc']);
        $this->assertSame('', $overlay['teams']['a']['players'][2]['name']);
        $this->assertCount(9, $overlay['teams']['a']['players']);
        $this->assertCount(9, $overlay['teams']['b']['players']);
        $this->assertSame('kaylus', $overlay['teams']['b']['players'][0]['name']);
    }

    public function test_l_overlay_affiche_equipes_portraits_pseudos_et_mercs(): void
    {
        $this->seedRosters([
            'format' => 'hl',
            'eyebrow' => 'ETF2L Highlander Saison 40 - Division 1',
            'title' => 'Rosters',
            'teams' => [
                'a' => [
                    'name' => 'DD14',
                    'avatar' => '',
                    'etf2l_id' => null,
                    'players' => $this->hlPlayers(['scout' => ['xine', false], 'soldier' => ['dexton123', true]]),
                ],
                'b' => [
                    'name' => 'AKATSUKI',
                    'avatar' => '',
                    'etf2l_id' => null,
                    'players' => $this->hlPlayers(['medic' => ['kaylus', true]]),
                ],
            ],
        ]);

        $response = $this->get('/roster-overlay/'.self::TOKEN);

        $response->assertOk()
            ->assertSee('ETF2L Highlander Saison 40 - Division 1')
            ->assertSee('Rosters')
            ->assertSee('DD14')
            ->assertSee('AKATSUKI')
            ->assertSee('xine')
            ->assertSee('dexton123')
            ->assertSee('kaylus')
            ->assertSee('MERC')
            // Portraits de classes : copie locale du wiki TF2.
            ->assertSee('_img/classes_portraits/scout.png')
            ->assertSee('_img/classes_portraits/medic.png')
            // Une seule équipe affichée à la fois : le bouton sous le
            // panneau bascule vers l'équipe masquée (noms dans les deux
            // libellés, le CSS n'en montre qu'un).
            ->assertSee('js-roster-switch')
            ->assertSee('Afficher AKATSUKI')
            ->assertSee('Afficher DD14');
    }

    public function test_l_overlay_6v6_affiche_six_classes_par_equipe(): void
    {
        $this->seedRosters(['format' => '6v6']);

        $response = $this->get('/roster-overlay/'.self::TOKEN);

        $response->assertOk()
            ->assertSee('js-roster-switch');

        $content = (string) $response->getContent();
        $this->assertSame(1, substr_count($content, 'roster-scene--6v6'));
        // Les deux équipes restent rendues (une seule affichée à la
        // fois) : deux fois six classes = douze cartes de classe.
        $this->assertSame(12, substr_count($content, '<li class="roster-class'));
    }

    public function test_l_endpoint_de_version_renvoie_la_version_courante(): void
    {
        $this->seedRosters();

        $this->get('/roster-overlay/'.self::TOKEN.'/version')
            ->assertOk()
            ->assertJson(['version' => (int) ($this->findOverlay()['version'])]);
    }

    public function test_la_suppression_efface_l_overlay(): void
    {
        $this->seedRosters();

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/rosters/'.self::TOKEN.'/delete')
            ->assertRedirect('/admin/overlay/rosters');

        $this->get('/roster-overlay/'.self::TOKEN)->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function findOverlay(): array
    {
        $overlay = (new RostersRepository)->find(self::TOKEN);
        $this->assertNotNull($overlay);

        return $overlay;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedRosters(array $overrides = []): void
    {
        $format = (string) ($overrides['format'] ?? 'hl');
        $classes = $format === '6v6'
            ? ['scout', 'scout', 'soldier', 'soldier', 'demoman', 'medic']
            : ['scout', 'soldier', 'pyro', 'demoman', 'heavyweapons', 'engineer', 'medic', 'sniper', 'spy'];

        (new RostersRepository)->save(array_merge([
            'token' => self::TOKEN,
            'format' => $format,
            'eyebrow' => '',
            'title' => 'Rosters',
            'teams' => [
                'a' => $this->team($classes),
                'b' => $this->team($classes),
            ],
            'created_at' => time() - 100,
        ], $overrides));
    }

    /**
     * Équipe avec des slots vides pour les classes données.
     *
     * @param  array<int, string>  $classes
     * @return array{name: string, avatar: string, etf2l_id: null, players: array<int, array{class: string, name: string, merc: bool}>}
     */
    private function team(array $classes): array
    {
        return [
            'name' => '',
            'avatar' => '',
            'etf2l_id' => null,
            'players' => array_map(
                static fn (string $class): array => ['class' => $class, 'name' => '', 'merc' => false],
                $classes
            ),
        ];
    }

    /**
     * Neuf slots Highlander, ceux nommés dans $named remplis (pseudo, merc).
     *
     * @param  array<string, array{string, bool}>  $named
     * @return array<int, array{class: string, name: string, merc: bool}>
     */
    private function hlPlayers(array $named): array
    {
        $classes = ['scout', 'soldier', 'pyro', 'demoman', 'heavyweapons', 'engineer', 'medic', 'sniper', 'spy'];

        return array_map(
            static fn (string $class): array => [
                'class' => $class,
                'name' => $named[$class][0] ?? '',
                'merc' => $named[$class][1] ?? false,
            ],
            $classes
        );
    }
}
