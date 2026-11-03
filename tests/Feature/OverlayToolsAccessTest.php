<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SteamId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hub des overlays OBS (/admin/overlays-stream) : les rôles caster et prod
 * (team Twitch) n'ont accès qu'aux outils d'overlay OBS — aucune autre page
 * du panel admin, aucune statistique du site. Les admins conservent tout.
 */
class OverlayToolsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Les pages outils overlay affichent le remplissage assisté ETF2L
        // (compétitions depuis le cache) : amorce vide pour éviter tout
        // appel HTTP réel en test.
        $this->seedEtf2lCompetitionsCache();
    }

    // ─── Accès au hub des overlays et aux outils overlay ──────────────────

    public function test_le_hub_des_overlays_et_les_outils_overlay_sont_interdits_aux_visiteurs(): void
    {
        $this->get('/admin/overlays-stream')->assertForbidden();
        $this->get('/admin/overlay')->assertForbidden();
        $this->get('/admin/series')->assertForbidden();
        $this->get('/admin/overlay/programme')->assertForbidden();
    }

    public function test_un_joueur_connecte_sans_role_n_a_pas_acces(): void
    {
        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/overlays-stream')->assertForbidden();

        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/overlay')->assertForbidden();

        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/series')->assertForbidden();

        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/overlay/programme')->assertForbidden();
    }

    public function test_un_caster_accede_au_hub_des_overlays_et_aux_outils(): void
    {
        $session = $this->loginAs('76561198012345678', caster: true);

        $this->withSession($session)->get('/admin/overlays-stream')->assertOk();
        $this->withSession($session)->get('/admin/overlay')->assertOk();
        $this->withSession($session)->get('/admin/series')->assertOk();
        $this->withSession($session)->get('/admin/overlay/programme')->assertOk();
    }

    public function test_un_membre_de_la_prod_accede_au_hub_des_overlays_et_aux_outils(): void
    {
        $session = $this->loginAs('76561198012345678', producer: true);

        $this->withSession($session)->get('/admin/overlays-stream')->assertOk();
        $this->withSession($session)->get('/admin/overlay')->assertOk();
        $this->withSession($session)->get('/admin/series')->assertOk();
        $this->withSession($session)->get('/admin/overlay/programme')->assertOk();
    }

    public function test_un_caster_n_accede_pas_au_rest_du_panel_admin(): void
    {
        $session = $this->loginAs('76561198012345678', caster: true);

        $this->withSession($session)->get('/admin/dashboard')->assertForbidden();
        $this->withSession($session)->get('/admin/list-staff')->assertForbidden();
        $this->withSession($session)->get('/admin/maps')->assertForbidden();
        $this->withSession($session)->get('/admin/guides')->assertForbidden();
    }

    public function test_un_admin_conserve_l_acces_au_hub_des_overlays(): void
    {
        $this->withSession($this->loginAs('76561198012345678', admin: true))
            ->get('/admin/overlays-stream')->assertOk();
    }

    // ─── Contenu du hub des overlays ────────────────────────────────────────

    public function test_le_hub_ne_montre_que_le_titre_et_les_six_outils(): void
    {
        $response = $this->withSession($this->loginAs('76561198012345678', caster: true))
            ->get('/admin/overlays-stream');

        $response->assertOk()
            ->assertSee('Gestion des Overlays OBS')
            ->assertSee('Overlay Logs (OBS)')
            ->assertSee('Overlay Scores')
            ->assertSee('Overlay Bracket')
            ->assertSee('Overlay Pick/Ban')
            ->assertSee('Overlay Rosters')
            ->assertSee('Overlay Programme')
            // Aucune statistique du site ne doit fuir sur ce hub.
            ->assertDontSee('Nombre de joueurs dans la base de données')
            ->assertDontSee('Joueurs enregistrés')
            ->assertDontSee('Matchs joués');
    }

    // ─── Lien de retour vers le hub sur les pages d'outils overlay ─────────

    public function test_les_pages_outils_overlay_ont_un_lien_de_retour_vers_le_hub(): void
    {
        $session = $this->loginAs('76561198012345678', caster: true);

        $this->withSession($session)->get('/admin/overlay')
            ->assertOk()
            ->assertSee('Retour aux overlays')
            ->assertSee('href="/admin/overlays-stream"', false);

        $this->withSession($session)->get('/admin/series')
            ->assertOk()
            ->assertSee('Retour aux overlays')
            ->assertSee('href="/admin/overlays-stream"', false);
    }

    public function test_le_lien_de_retour_d_un_admin_pointe_aussi_vers_le_hub(): void
    {
        $this->withSession($this->loginAs('76561198012345678', admin: true))
            ->get('/admin/overlay')
            ->assertOk()
            ->assertSee('Retour aux overlays')
            ->assertSee('href="/admin/overlays-stream"', false);
    }

    // ─── Lien de retour vers le dashboard admin sur le hub ────────────────

    public function test_le_hub_affiche_le_lien_de_retour_vers_le_dashboard_admin_pour_un_admin(): void
    {
        $this->withSession($this->loginAs('76561198012345678', admin: true))
            ->get('/admin/overlays-stream')
            ->assertOk()
            ->assertSee('Retour au panel d\'administration', false)
            ->assertSee('href="/admin/dashboard"', false);
    }

    public function test_le_hub_n_affiche_pas_le_lien_du_dashboard_au_caster_ni_a_la_prod(): void
    {
        $caster = $this->loginAs('76561198012345678', caster: true);
        $prod = $this->loginAs('76561198012345678', producer: true);

        $this->withSession($caster)
            ->get('/admin/overlays-stream')
            ->assertOk()
            ->assertDontSee('Retour au panel d\'administration', false)
            ->assertDontSee('href="/admin/dashboard"', false);

        $this->withSession($prod)
            ->get('/admin/overlays-stream')
            ->assertOk()
            ->assertDontSee('Retour au panel d\'administration', false)
            ->assertDontSee('href="/admin/dashboard"', false);
    }

    // ─── Changement de rôle effectif sans reconnexion ─────────────────────

    public function test_une_revocation_de_role_par_un_admin_prend_effet_sans_reconnexion(): void
    {
        $prod = $this->loginAs('76561198012345678', producer: true);

        // Le membre de la prod a bien accès aux outils overlay.
        $this->withSession($prod)->get('/admin/overlays-stream')->assertOk();

        // Un admin retire son rôle prod via le panel (même session conservée).
        $this->withSession($this->loginAs('76561198087654321', admin: true))
            ->post('/api/admin/player-update', [
                'target_steamid' => '76561198012345678',
                'display_name' => 'MembreProd',
                'country' => 'fr',
            ])
            ->assertRedirect();

        // Sans reconnexion, l'accès aux outils overlay est désormais refusé.
        $this->withSession($prod)->get('/admin/overlays-stream')->assertForbidden();
        $this->withSession($prod)->get('/admin/overlay')->assertForbidden();
        $this->withSession($prod)->get('/admin/series')->assertForbidden();
    }

    public function test_un_octroi_de_role_prend_effet_sans_reconnexion(): void
    {
        $session = $this->loginAs('76561198012345678');

        $this->withSession($session)->get('/admin/overlays-stream')->assertForbidden();

        // Un admin ajoute le rôle caster dans la BDD (même session conservée).
        DB::table('players_info')
            ->where('steamid', SteamId::toSteamId3('76561198012345678'))
            ->update(['is_caster' => 1]);

        $this->withSession($session)->get('/admin/overlays-stream')->assertOk();
    }
}
