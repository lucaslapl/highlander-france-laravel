<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel admin restreint (/admin/panel) : les rôles caster et prod (team
 * Twitch) n'ont accès qu'aux outils d'overlay OBS — aucune autre page du
 * panel admin, aucune statistique du site. Les admins conservent tout.
 */
class OverlayToolsAccessTest extends TestCase
{
    use RefreshDatabase;

    // ─── Accès au panel restreint et aux outils overlay ───────────────────

    public function test_le_panel_restreint_et_les_outils_overlay_sont_interdits_aux_visiteurs(): void
    {
        $this->get('/admin/panel')->assertForbidden();
        $this->get('/admin/overlay')->assertForbidden();
        $this->get('/admin/series')->assertForbidden();
    }

    public function test_un_joueur_connecte_sans_role_n_a_pas_acces(): void
    {
        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/panel')->assertForbidden();

        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/overlay')->assertForbidden();

        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/series')->assertForbidden();
    }

    public function test_un_caster_accede_au_panel_restreint_et_aux_deux_outils(): void
    {
        $session = ['steamid' => '76561198012345678', 'is_caster' => true];

        $this->withSession($session)->get('/admin/panel')->assertOk();
        $this->withSession($session)->get('/admin/overlay')->assertOk();
        $this->withSession($session)->get('/admin/series')->assertOk();
    }

    public function test_un_membre_de_la_prod_accede_au_panel_restreint_et_aux_deux_outils(): void
    {
        $session = ['steamid' => '76561198012345678', 'is_producer' => true];

        $this->withSession($session)->get('/admin/panel')->assertOk();
        $this->withSession($session)->get('/admin/overlay')->assertOk();
        $this->withSession($session)->get('/admin/series')->assertOk();
    }

    public function test_un_caster_n_accede_pas_au_rest_du_panel_admin(): void
    {
        $session = ['steamid' => '76561198012345678', 'is_caster' => true];

        $this->withSession($session)->get('/admin/dashboard')->assertForbidden();
        $this->withSession($session)->get('/admin/list-staff')->assertForbidden();
        $this->withSession($session)->get('/admin/maps')->assertForbidden();
        $this->withSession($session)->get('/admin/guides')->assertForbidden();
    }

    public function test_un_admin_conserve_l_acces_au_panel_restreint(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_admin' => true])
            ->get('/admin/panel')->assertOk();
    }

    // ─── Contenu du panel restreint ────────────────────────────────────────

    public function test_le_panel_restreint_ne_montre_que_le_titre_et_les_deux_boutons(): void
    {
        $response = $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/panel');

        $response->assertOk()
            ->assertSee('Panel admin')
            ->assertSee('Overlay Logs (OBS)')
            ->assertSee('Overlay Scores')
            // Aucune statistique du site ne doit fuiter sur ce panel.
            ->assertDontSee('Nombre de joueurs dans la base de données')
            ->assertDontSee('Joueurs enregistrés')
            ->assertDontSee('Matchs joués');
    }

    // ─── Lien de retour vers le panel sur les pages d'outils overlay ───────

    public function test_les_pages_outils_overlay_ont_un_lien_de_retour_vers_le_panel(): void
    {
        $session = ['steamid' => '76561198012345678', 'is_caster' => true];

        $this->withSession($session)->get('/admin/overlay')
            ->assertOk()
            ->assertSee('Retour au panel admin')
            ->assertSee('href="/admin/panel"', false);

        $this->withSession($session)->get('/admin/series')
            ->assertOk()
            ->assertSee('Retour au panel admin')
            ->assertSee('href="/admin/panel"', false);
    }

    public function test_le_lien_de_retour_d_un_admin_pointe_vers_le_dashboard(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_admin' => true])
            ->get('/admin/overlay')
            ->assertOk()
            ->assertSee('Retour au panel admin')
            ->assertSee('href="/admin/dashboard"', false);
    }
}
