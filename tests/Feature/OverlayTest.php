<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OverlayRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Outil « Overlay Logs » : accès réservé aux admins côté panel, vue overlay
 * publique par token (OBS), mise à jour des équipes et suppression.
 */
class OverlayTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaa';

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Dossier de données dédié aux tests : rien ne pollue le vrai
        // storage/app/hlfr et tout est nettoyé en tearDown.
        $this->dataDir = storage_path('app/hlfr_overlays_test');
        config(['hlfr.data_dir' => $this->dataDir]);
    }

    protected function tearDown(): void
    {
        // Le repository écrit sous <data_dir>/overlays/ : nettoyage complet.
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
    private function overlayFixture(): array
    {
        return [
            'token' => self::TOKEN,
            'log_id' => 4059225,
            'title' => 'Highlander France: BLU vs RED',
            'map' => 'koth_proot_b5b',
            'date' => 1779390707,
            'length' => 1783,
            'created_at' => time() - 60,
            'teams' => [
                'red' => ['name' => 'RED', 'score' => 3, 'avatar_url' => null],
                'blue' => ['name' => 'BLU', 'score' => 2, 'avatar_url' => null],
            ],
            'players' => [
                'red' => [[
                    'steamid3' => '[U:1:111]', 'name' => 'ScoutFR', 'class' => 'scout',
                    'kills' => 30, 'assists' => 5, 'deaths' => 10, 'dmg' => 6000,
                    'dapm' => 200, 'hr' => 5000, 'dt' => 4000, 'kd' => 3.0, 'best' => ['kills'],
                ]],
                'blue' => [[
                    'steamid3' => '[U:1:444]', 'name' => 'ScoutEN', 'class' => 'scout',
                    'kills' => 20, 'assists' => 3, 'deaths' => 14, 'dmg' => 4000,
                    'dapm' => 150, 'hr' => 6000, 'dt' => 2500, 'kd' => 1.43, 'best' => ['hr'],
                ]],
            ],
            'medics' => [
                'red' => ['heal' => 35000, 'ubers' => 10, 'drops' => 1, 'avg_uber_length' => 6.0, 'count' => 1],
                'blue' => ['heal' => 20000, 'ubers' => 4, 'drops' => 0, 'avg_uber_length' => null, 'count' => 1],
            ],
            'style' => ['panel' => true, 'opacity' => 70, 'blur' => 6],
        ];
    }

    private function seedOverlay(): void
    {
        (new OverlayRepository)->save($this->overlayFixture());
    }

    /**
     * @return array<string, mixed>
     */
    private function adminSession(): array
    {
        return ['steamid' => '76561198012345678', 'is_admin' => true];
    }

    // ─── Accès ─────────────────────────────────────────────────────────────

    public function test_le_panel_overlay_est_reserve_aux_admins(): void
    {
        $this->get('/admin/overlay')->assertForbidden();
        $this->post('/admin/overlay/generate', ['log' => '4059225'])->assertForbidden();
        $this->get('/admin/overlay/'.self::TOKEN)->assertForbidden();
        $this->post('/admin/overlay/'.self::TOKEN.'/delete')->assertForbidden();
    }

    public function test_l_index_admin_liste_les_overlays(): void
    {
        $this->seedOverlay();

        $this->withSession($this->adminSession())
            ->get('/admin/overlay')
            ->assertOk()
            ->assertSee('4059225')
            ->assertSee(url('/overlay/'.self::TOKEN))
            // Le formulaire de génération permet de préparer noms et avatars,
            // avec les équipes présentées comme A / B (côtés arbitraires).
            ->assertSee('red_name')
            ->assertSee('blue_name')
            ->assertSee('red_avatar_url')
            ->assertSee('blue_avatar_url')
            ->assertSee('Équipe A')
            ->assertSee('Équipe B')
            // Plus de bouton d'interversion côté page de génération :
            // il vit désormais sur la page d'édition de l'overlay.
            ->assertDontSee('js-overlay-swap');
    }

    public function test_la_generation_rejette_une_saisie_invalide(): void
    {
        $this->withSession($this->adminSession())
            ->post('/admin/overlay/generate', ['log' => 'n-importe-quoi'])
            ->assertRedirect();

        $this->assertSame([], (new OverlayRepository)->all());
    }

    public function test_un_token_overlay_inconnu_renvoie_404(): void
    {
        $this->get('/overlay/'.self::TOKEN)->assertNotFound();
        $this->get('/overlay/'.self::TOKEN.'/version')->assertNotFound();
        $this->get('/overlay/'.self::TOKEN.'/avatar/red')->assertNotFound();
    }

    // ─── Vue overlay publique ──────────────────────────────────────────────

    public function test_la_vue_overlay_affiche_equipes_scores_joueurs_et_medics(): void
    {
        $this->seedOverlay();

        $response = $this->get('/overlay/'.self::TOKEN);

        $response->assertOk();
        $this->assertStringContainsString('ScoutFR', (string) $response->getContent());
        $this->assertStringContainsString('35 000', (string) $response->getContent());
        $this->assertStringContainsString('best', (string) $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_la_vue_overlay_affiche_le_diminutif_de_la_map(): void
    {
        $overlay = $this->overlayFixture();
        $overlay['map'] = 'koth_product_final';
        (new OverlayRepository)->save($overlay);

        $response = $this->get('/overlay/'.self::TOKEN);

        $response->assertOk();
        $this->assertStringContainsString('Product', (string) $response->getContent());
        $this->assertStringNotContainsString('koth_product_final', (string) $response->getContent());

        // Second cas : suffixe de version chiffré (cp_steel_f12 → Steel).
        $overlay['token'] = 'bbbbbbbbbbbbbbbb';
        $overlay['map'] = 'cp_steel_f12';
        (new OverlayRepository)->save($overlay);

        $response = $this->get('/overlay/bbbbbbbbbbbbbbbb');

        $response->assertOk();
        $this->assertStringContainsString('Steel', (string) $response->getContent());
        $this->assertStringNotContainsString('cp_steel_f12', (string) $response->getContent());
    }

    public function test_le_endpoint_de_version_expose_la_version_courante(): void
    {
        $this->seedOverlay();

        $this->get('/overlay/'.self::TOKEN.'/version')
            ->assertOk()
            ->assertJsonStructure(['version']);
    }

    // ─── Édition ──────────────────────────────────────────────────────────

    public function test_l_admin_modifie_noms_et_avatars_et_la_version_bump(): void
    {
        $this->seedOverlay();

        $repo = new OverlayRepository;
        $before = $repo->find(self::TOKEN);

        // Garantit un vrai bump de version (résolution à la seconde).
        usleep(1100000);

        $this->withSession($this->adminSession())
            ->post('/admin/overlay/'.self::TOKEN.'/update', [
                'red_name' => 'Les Étoiles',
                'blue_name' => 'Baguettes',
                'red_avatar_url' => 'https://example.com/logo.png',
                'blue_avatar_url' => '',
            ])
            ->assertRedirect();

        $after = $repo->find(self::TOKEN);
        $this->assertSame('Les Étoiles', $after['teams']['red']['name']);
        $this->assertSame('Baguettes', $after['teams']['blue']['name']);
        $this->assertSame('https://example.com/logo.png', $after['teams']['red']['avatar_url']);
        $this->assertNull($after['teams']['blue']['avatar_url']);
        $this->assertGreaterThan((int) $before['version'], (int) $after['version']);

        // L'overlay reflète le nouveau nom (polling OBS → reload).
        $this->get('/overlay/'.self::TOKEN)->assertOk()->assertSee('Les Étoiles');
    }

    public function test_les_urls_d_avatars_sont_memorisees_pour_reutilisation(): void
    {
        $this->seedOverlay();

        $this->withSession($this->adminSession())
            ->post('/admin/overlay/'.self::TOKEN.'/update', [
                'red_name' => 'RED',
                'blue_name' => 'BLU',
                'red_avatar_url' => 'https://example.com/logo.png',
                'blue_avatar_url' => 'https://example.com/logo-b.png',
            ])
            ->assertRedirect();

        // Les deux URL sont mémorisées et proposées en saisie prédictive
        // sur la page de génération comme sur la page d'édition.
        $session = $this->adminSession();
        $this->withSession($session)
            ->get('/admin/overlay')
            ->assertOk()
            ->assertSee('https://example.com/logo.png')
            ->assertSee('https://example.com/logo-b.png');

        $this->withSession($session)
            ->get('/admin/overlay/'.self::TOKEN)
            ->assertOk()
            ->assertSee('https://example.com/logo.png')
            ->assertSee('https://example.com/logo-b.png');

        // La dernière URL mémorisée passe en tête de liste.
        $this->assertSame(
            ['https://example.com/logo-b.png', 'https://example.com/logo.png'],
            array_slice((new OverlayRepository)->avatarUrls(), 0, 2)
        );
    }

    public function test_l_upload_d_avatar_n_est_plus_propose(): void
    {
        $this->seedOverlay();

        $session = $this->adminSession();

        // Les routes d'upload ont disparu.
        $this->withSession($session)
            ->post('/admin/overlay/'.self::TOKEN.'/avatar', [])
            ->assertNotFound();
        $this->withSession($session)
            ->post('/admin/overlay/'.self::TOKEN.'/avatar/delete', ['team' => 'red'])
            ->assertNotFound();

        // La page d'édition ne propose plus de champ fichier.
        $this->withSession($session)
            ->get('/admin/overlay/'.self::TOKEN)
            ->assertOk()
            ->assertDontSee('type="file"', false)
            // Le bouton d'interversion A / B est mis en avant et expliqué.
            ->assertSee('Intervertir les équipes A / B');
    }

    public function test_l_admin_intervertit_les_equipes_noms_avatars(): void
    {
        $this->seedOverlay();

        $repo = new OverlayRepository;
        $overlay = $repo->find(self::TOKEN);
        $overlay['teams']['red'] = ['name' => 'Rouges', 'score' => 3, 'avatar_url' => 'https://example.com/rouge.png'];
        $overlay['teams']['blue'] = ['name' => 'Bleus', 'score' => 2, 'avatar_url' => null];
        $repo->save($overlay);

        // Faux avatars uploadés pour vérifier l'échange des fichiers.
        $avatarDir = storage_path('app/public/overlay-avatars/'.self::TOKEN);
        if (! is_dir($avatarDir)) {
            @mkdir($avatarDir, 0755, true);
        }
        file_put_contents($avatarDir.'/red.png', 'avatar-rouge');
        file_put_contents($avatarDir.'/blue.webp', 'avatar-bleu');

        try {
            $this->withSession($this->adminSession())
                ->post('/admin/overlay/'.self::TOKEN.'/swap')
                ->assertRedirect();

            $after = $repo->find(self::TOKEN);
            $this->assertSame('Bleus', $after['teams']['red']['name']);
            $this->assertSame('Rouges', $after['teams']['blue']['name']);
            $this->assertSame('https://example.com/rouge.png', $after['teams']['blue']['avatar_url']);
            $this->assertNull($after['teams']['red']['avatar_url']);
            // Les scores ne bougent pas : seules les personnalisation s'échangent.
            $this->assertSame(3, $after['teams']['red']['score']);
            $this->assertSame(2, $after['teams']['blue']['score']);

            // Les fichiers uploadés sont échangés, même avec des extensions différentes.
            $this->assertSame('avatar-bleu', (string) file_get_contents($avatarDir.'/red.webp'));
            $this->assertSame('avatar-rouge', (string) file_get_contents($avatarDir.'/blue.png'));

            // L'overlay répercute l'interversion (polling OBS → reload).
            $this->get('/overlay/'.self::TOKEN)->assertOk()->assertSee('Bleus');
        } finally {
            foreach (glob($avatarDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($avatarDir);
        }
    }

    public function test_la_suppression_efface_l_overlay_et_sa_vue(): void
    {
        $this->seedOverlay();

        $this->withSession($this->adminSession())
            ->post('/admin/overlay/'.self::TOKEN.'/delete')
            ->assertRedirect('/admin/overlay');

        $this->assertNull((new OverlayRepository)->find(self::TOKEN));
        $this->get('/overlay/'.self::TOKEN)->assertNotFound();
    }
}
