<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PickBanRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Overlay OBS pick / ban de maps : pages publiques par token (source
 * navigateur OBS, fond transparent) et endpoint de version interrogé par
 * la page pour se rafraîchir. L'outil admin (création / édition) impose le
 * format strict : 3 picks + 3 bans ou 5 picks + 1 ban, comptes exacts à
 * l'enregistrement. Aucune donnée sensible : noms d'équipes et maps de
 * matchs publics.
 */
class PickBanOverlayTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaa';

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Dossier de données dédié aux tests : rien ne pollue le vrai
        // storage/app/hlfr et tout est nettoyé en tearDown.
        $this->dataDir = storage_path('app/hlfr_pickban_overlay_test');
        config(['hlfr.data_dir' => $this->dataDir]);

        // Le remplissage assisté ETF2L de l'éditeur est servi depuis le
        // cache : amorce vide pour rester sans appel HTTP réel.
        $this->seedEtf2lCompetitionsCache();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataDir.'/pickbans/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataDir.'/pickbans');
        @rmdir($this->dataDir);

        parent::tearDown();
    }

    public function test_un_token_inconnu_renvoie_404(): void
    {
        $this->get('/pickban-overlay/'.self::TOKEN)->assertNotFound();
        $this->get('/pickban-overlay/'.self::TOKEN.'/version')->assertNotFound();
    }

    public function test_l_overlay_est_interdit_aux_visiteurs_et_aux_joueurs_sans_role(): void
    {
        $this->get('/admin/overlay/pickban')->assertForbidden();

        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/overlay/pickban')->assertForbidden();
    }

    public function test_un_caster_accede_a_l_outil(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/pickban')->assertOk();
    }

    public function test_l_editeur_s_affiche_avec_les_cartes_et_le_formulaire(): void
    {
        $this->seedPickBan([
            'cards' => [
                ['map' => 'upward', 'image' => '', 'action' => 'ban', 'team' => 'a'],
                ['map' => 'vigil', 'image' => '', 'action' => 'ban', 'team' => 'b'],
                ['map' => 'swift', 'image' => '', 'action' => 'pick', 'team' => 'b'],
                ['map' => 'steel', 'image' => '', 'action' => 'pick', 'team' => 'a'],
                ['map' => 'product', 'image' => '', 'action' => 'ban', 'team' => 'a'],
                ['map' => 'proot', 'image' => '', 'action' => 'pick', 'team' => 'a'],
            ],
        ]);

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/pickban/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Overlay Pick/Ban')
            ->assertSee('Équipe A')
            ->assertSee('Équipe B')
            ->assertSee('upward')
            ->assertSee('PICK')
            ->assertSee('BAN');
    }

    public function test_la_creation_posse_six_cartes_selon_le_gabarit_du_format(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/pickban/create', [
                'format' => '3_3',
                'eyebrow' => 'ETF2L Highlander Saison 36 - Division 1 - Finale',
                'title' => 'Picks & Bans',
            ])
            ->assertRedirect();

        $overlays = (new PickBanRepository)->all();
        $this->assertCount(1, $overlays);

        $overlay = (new PickBanRepository)->find((string) $overlays[0]['token']);
        $this->assertSame('3_3', $overlay['format']);
        $this->assertCount(6, $overlay['cards']);
        $this->assertSame(3, count(array_filter($overlay['cards'], fn (array $c): bool => $c['action'] === 'pick')));
        $this->assertSame(3, count(array_filter($overlay['cards'], fn (array $c): bool => $c['action'] === 'ban')));
    }

    public function test_l_overlay_affiche_maps_equipes_et_actions(): void
    {
        $this->seedPickBan([
            'format' => '3_3',
            'eyebrow' => 'ETF2L Highlander Saison 36 - Division 1 - Finale',
            'title' => 'Picks & Bans',
            'teams' => [
                'a' => ['name' => 'DD14', 'avatar' => ''],
                'b' => ['name' => 'AKATSUKI', 'avatar' => ''],
            ],
            'cards' => [
                ['map' => 'upward', 'image' => '', 'action' => 'ban', 'team' => 'a'],
                ['map' => 'vigil', 'image' => '', 'action' => 'ban', 'team' => 'b'],
                ['map' => 'swift', 'image' => '', 'action' => 'pick', 'team' => 'b'],
                ['map' => 'steel', 'image' => '', 'action' => 'pick', 'team' => 'a'],
                ['map' => 'product', 'image' => '', 'action' => 'ban', 'team' => 'a'],
                ['map' => 'proot', 'image' => '', 'action' => 'pick', 'team' => 'a'],
            ],
        ]);

        $response = $this->get('/pickban-overlay/'.self::TOKEN);

        $response->assertOk()
            ->assertSee('ETF2L Highlander Saison 36 - Division 1 - Finale')
            ->assertSee('Picks &amp; Bans')
            ->assertSee('UPWARD')
            ->assertSee('VIGIL')
            ->assertSee('SWIFT')
            ->assertSee('STEEL')
            ->assertSee('PRODUCT')
            ->assertSee('PROOT')
            ->assertSee('DD14')
            ->assertSee('AKATSUKI')
            ->assertSee('PICK')
            ->assertSee('BAN');
    }

    public function test_la_page_overlay_n_expose_rien_de_sensible(): void
    {
        $this->seedPickBan();

        $response = $this->get('/pickban-overlay/'.self::TOKEN);
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_l_endpoint_de_version_renvoie_la_version_courante(): void
    {
        $this->seedPickBan();

        $this->get('/pickban-overlay/'.self::TOKEN.'/version')
            ->assertOk()
            ->assertJson(['version' => (int) ($this->findOverlay()['version'])]);
    }

    public function test_la_mise_a_jour_refuse_un_compte_pick_ban_incoherent_avec_le_format(): void
    {
        $this->seedPickBan(['format' => '3_3']);

        $cards = [
            ['map' => 'upward', 'image' => '', 'action' => 'ban', 'team' => 'a'],
            ['map' => 'vigil', 'image' => '', 'action' => 'ban', 'team' => 'b'],
            ['map' => 'swift', 'image' => '', 'action' => 'pick', 'team' => 'b'],
            ['map' => 'steel', 'image' => '', 'action' => 'pick', 'team' => 'a'],
            ['map' => 'product', 'image' => '', 'action' => 'ban', 'team' => 'a'],
            ['map' => 'proot', 'image' => '', 'action' => 'ban', 'team' => 'b'],
        ];

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/pickban/'.self::TOKEN.'/update', [
                'format' => '3_3',
                'eyebrow' => '',
                'title' => 'Picks & Bans',
                'cards' => $cards,
            ])
            ->assertSessionHas('error');

        // Rien n'a été enregistré : les cartes d'origine sont intactes.
        $overlay = $this->findOverlay();
        $this->assertSame([], $overlay['cards']);
    }

    public function test_la_mise_a_jour_enregistre_un_format_3_3_valide(): void
    {
        $this->seedPickBan(['format' => '3_3']);

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/pickban/'.self::TOKEN.'/update', [
                'format' => '3_3',
                'eyebrow' => 'ETF2L Highlander Saison 36',
                'title' => 'Picks & Bans',
                'team_a' => ['name' => 'DD14', 'avatar' => ''],
                'team_b' => ['name' => 'AKATSUKI', 'avatar' => ''],
                'cards' => [
                    ['map' => 'pl_upward', 'image' => 'https://example.com/upward.jpg', 'action' => 'ban', 'team' => 'a'],
                    ['map' => 'cp_vigil', 'image' => '', 'action' => 'ban', 'team' => 'b'],
                    ['map' => 'koth_swift', 'image' => '', 'action' => 'pick', 'team' => 'b'],
                    ['map' => 'cp_steel', 'image' => '', 'action' => 'pick', 'team' => 'a'],
                    ['map' => 'koth_product', 'image' => '', 'action' => 'ban', 'team' => 'a'],
                    ['map' => 'pl_proot', 'image' => '', 'action' => 'pick', 'team' => 'a'],
                ],
            ])
            ->assertSessionHas('success');

        $overlay = $this->findOverlay();
        $this->assertSame('DD14', $overlay['teams']['a']['name']);
        $this->assertSame('AKATSUKI', $overlay['teams']['b']['name']);
        $this->assertSame('pl_upward', $overlay['cards'][0]['map']);
        $this->assertSame('https://example.com/upward.jpg', $overlay['cards'][0]['image']);
        $this->assertSame(3, count(array_filter($overlay['cards'], fn (array $c): bool => $c['action'] === 'pick')));
    }

    public function test_la_mise_a_jour_enregistre_un_format_5_1_valide(): void
    {
        $this->seedPickBan(['format' => '5_1']);

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/pickban/'.self::TOKEN.'/update', [
                'format' => '5_1',
                'eyebrow' => '',
                'title' => 'Picks & Bans',
                'cards' => [
                    ['map' => 'cp_process', 'image' => '', 'action' => 'ban', 'team' => 'b'],
                    ['map' => 'pl_upward', 'image' => '', 'action' => 'pick', 'team' => 'a'],
                    ['map' => 'koth_product', 'image' => '', 'action' => 'pick', 'team' => 'a'],
                    ['map' => 'cp_steel', 'image' => '', 'action' => 'pick', 'team' => 'b'],
                    ['map' => 'pl_proot', 'image' => '', 'action' => 'pick', 'team' => 'b'],
                    ['map' => 'cp_gullywash', 'image' => '', 'action' => 'pick', 'team' => 'a'],
                ],
            ])
            ->assertSessionHas('success');

        $overlay = $this->findOverlay();
        $this->assertSame(5, count(array_filter($overlay['cards'], fn (array $c): bool => $c['action'] === 'pick')));
        $this->assertSame(1, count(array_filter($overlay['cards'], fn (array $c): bool => $c['action'] === 'ban')));
    }

    public function test_la_suppression_efface_l_overlay(): void
    {
        $this->seedPickBan();

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/pickban/'.self::TOKEN.'/delete')
            ->assertRedirect('/admin/overlay/pickban');

        $this->get('/pickban-overlay/'.self::TOKEN)->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function findOverlay(): array
    {
        $overlay = (new PickBanRepository)->find(self::TOKEN);
        $this->assertNotNull($overlay);

        return $overlay;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedPickBan(array $overrides = []): void
    {
        (new PickBanRepository)->save(array_merge([
            'token' => self::TOKEN,
            'format' => '3_3',
            'eyebrow' => '',
            'title' => 'Picks & Bans',
            'teams' => [
                'a' => ['name' => '', 'avatar' => ''],
                'b' => ['name' => '', 'avatar' => ''],
            ],
            'cards' => [],
            'created_at' => time() - 100,
        ], $overrides));
    }
}
