<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProgrammeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Overlay OBS « Programme » : écran d'attente / de présentation (horloge,
 * décompte, équipes du jour, diaporama de messages, programme de la
 * semaine). Pages publiques par token (source navigateur OBS, fond
 * transparent) et endpoint de version interrogé par la page pour se
 * rafraîchir. L'outil admin (création / édition) gère le décompte en
 * deux modes — date/heure cible Europe/Paris ou durée relancée —
 * ajustable à la volée en secondes ; le remplissage des équipes est
 * assisté par un match ETF2L servi depuis le cache (aucun appel HTTP
 * réel en test). Aucune donnée sensible : noms d'équipes, horaires et
 * messages de programme publics.
 */
class ProgrammeOverlayTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaa';

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Dossier de données dédié aux tests : rien ne pollue le vrai
        // storage/app/hlfr et tout est nettoyé en tearDown.
        $this->dataDir = storage_path('app/hlfr_programme_overlay_test');
        config(['hlfr.data_dir' => $this->dataDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataDir.'/programmes/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataDir.'/programmes');
        @rmdir($this->dataDir);

        parent::tearDown();
    }

    public function test_un_token_inconnu_renvoie_404(): void
    {
        $this->get('/programme-overlay/'.self::TOKEN)->assertNotFound();
        $this->get('/programme-overlay/'.self::TOKEN.'/version')->assertNotFound();
    }

    public function test_l_outil_est_interdit_aux_visiteurs_et_aux_joueurs_sans_role(): void
    {
        $this->get('/admin/overlay/programme')->assertForbidden();

        $this->withSession(['steamid' => '76561198012345678'])
            ->get('/admin/overlay/programme')->assertForbidden();
    }

    public function test_un_caster_accede_a_l_outil(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/programme')->assertOk();
    }

    public function test_la_creation_pose_les_valeurs_par_defaut(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/create', [
                'name' => 'Programme du dimanche',
            ])
            ->assertRedirect();

        $overlays = (new ProgrammeRepository)->all();
        $this->assertCount(1, $overlays);

        $overlay = (new ProgrammeRepository)->find((string) $overlays[0]['token']);
        $this->assertSame('Programme du dimanche', $overlay['name']);
        $this->assertSame('Bienvenue sur Highlander France !', $overlay['welcome']);
        $this->assertSame('duration', $overlay['countdown']['mode']);
        $this->assertSame(3600, $overlay['countdown']['duration_s']);
        $this->assertSame('EN DIRECT', $overlay['countdown']['zero_text']);
        $this->assertNotEmpty($overlay['messages']);
        $this->assertSame(8, $overlay['slideshow']['display_s']);
        $this->assertSame(1, $overlay['slideshow']['fade_s']);
        $this->assertSame([], $overlay['schedule']);
    }

    public function test_l_editeur_s_affiche_avec_le_formulaire(): void
    {
        $this->seedProgramme();

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/programme/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Overlay Programme')
            ->assertSee('Équipes du jour')
            ->assertSee('Programme de la semaine')
            ->assertSee('Diaporama de messages')
            ->assertSee('Ajuster le décompte à la volée');
    }

    public function test_la_mise_a_jour_enregistre_une_duree_et_les_messages(): void
    {
        $this->seedProgramme();

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/update', [
                'name' => 'Programme du dimanche',
                'welcome' => 'Bienvenue sur Highlander France !',
                'team_a' => 'TWICE',
                'team_b' => 'ChudsX6',
                'team_a_avatar' => 'https://example.com/twice.png',
                'team_b_avatar' => 'https://example.com/chuds.png',
                'countdown_mode' => 'duration',
                'countdown_label' => 'Début dans',
                'countdown_duration_min' => 45,
                'countdown_zero_text' => 'EN DIRECT',
                'messages' => "Message un\n\n  \nMessage deux",
                'slideshow_display_s' => 12,
                'slideshow_fade_s' => 2,
                'schedule' => [
                    ['day' => 'Dimanche 11 Octobre', 'time' => '21h', 'label' => 'Finale Loser Bracket - Prem', 'team_a' => 'TWICE', 'team_b' => 'ChudsX6'],
                    ['day' => '', 'time' => '', 'label' => '', 'team_a' => '', 'team_b' => ''],
                ],
            ])
            ->assertSessionHas('success');

        $overlay = $this->findOverlay();
        $this->assertSame('TWICE', $overlay['team_a']);
        $this->assertSame('ChudsX6', $overlay['team_b']);
        $this->assertSame('https://example.com/twice.png', $overlay['team_a_avatar']);
        $this->assertSame('https://example.com/chuds.png', $overlay['team_b_avatar']);
        $this->assertSame(45 * 60, $overlay['countdown']['duration_s']);
        // Les lignes vides du textarea sont filtrées.
        $this->assertSame(['Message un', 'Message deux'], $overlay['messages']);
        $this->assertSame(12, $overlay['slideshow']['display_s']);
        $this->assertSame(2, $overlay['slideshow']['fade_s']);
        // Les entrées entièrement vides du programme sont ignorées.
        $this->assertCount(1, $overlay['schedule']);
        $this->assertSame('Dimanche 11 Octobre', $overlay['schedule'][0]['day']);
        $this->assertSame('Finale Loser Bracket - Prem', $overlay['schedule'][0]['label']);
    }

    public function test_un_avatar_d_equipe_invalide_est_rejete(): void
    {
        $this->seedProgramme();

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/update', [
                'name' => 'Programme du dimanche',
                'welcome' => 'Bienvenue sur Highlander France !',
                'countdown_mode' => 'duration',
                'countdown_duration_min' => 60,
                'team_a' => 'TWICE',
                'team_a_avatar' => 'javascript:alert(1)',
                'messages' => '',
                'slideshow_display_s' => 8,
                'slideshow_fade_s' => 1,
            ])
            ->assertSessionHas('success');

        // Seule une URL http(s) est conservée.
        $this->assertSame('', $this->findOverlay()['team_a_avatar']);
    }

    public function test_un_echec_d_enregistrement_preserve_les_entrees_du_programme(): void
    {
        $this->seedProgramme();

        // Date/heure absentes en mode date/heure : l'enregistrement
        // échoue, mais les entrées saisies doivent revenir dans le
        // formulaire (old()), pas disparaître.
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/update', [
                'name' => 'Programme du dimanche',
                'welcome' => 'Bienvenue sur Highlander France !',
                'countdown_mode' => 'datetime',
                'messages' => '',
                'slideshow_display_s' => 8,
                'slideshow_fade_s' => 1,
                'schedule' => [
                    ['day' => 'Dimanche 11 Octobre', 'time' => '21h', 'label' => 'Finale Loser Bracket - Prem', 'team_a' => 'TWICE', 'team_b' => 'ChudsX6'],
                ],
            ])
            ->assertSessionHas('error');

        // Rien n'a été enregistré côté payload…
        $this->assertSame([], $this->findOverlay()['schedule']);

        // … mais l'éditeur ré-affiche l'entrée saisie.
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/programme/'.self::TOKEN)
            ->assertOk()
            ->assertSee('Dimanche 11 Octobre')
            ->assertSee('Finale Loser Bracket - Prem');
    }

    public function test_la_mise_a_jour_convertit_la_date_heure_de_paris_en_timestamp(): void
    {
        $this->seedProgramme();

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/update', [
                'name' => 'Programme du dimanche',
                'welcome' => 'Bienvenue sur Highlander France !',
                'team_a' => '',
                'team_b' => '',
                'countdown_mode' => 'datetime',
                'countdown_label' => 'Début dans',
                'countdown_date' => '2026-10-18',
                'countdown_time' => '21:00',
                'countdown_zero_text' => 'EN DIRECT',
                'messages' => '',
                'slideshow_display_s' => 8,
                'slideshow_fade_s' => 1,
            ])
            ->assertSessionHas('success');

        $overlay = $this->findOverlay();
        $this->assertSame('datetime', $overlay['countdown']['mode']);

        $expected = (new \DateTimeImmutable('2026-10-18 21:00', new \DateTimeZone('Europe/Paris')))->getTimestamp();
        $this->assertSame($expected, $overlay['countdown']['target_ts']);
    }

    public function test_le_mode_date_heure_exige_une_date_et_une_heure(): void
    {
        $this->seedProgramme();

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/update', [
                'name' => 'Programme du dimanche',
                'welcome' => 'Bienvenue sur Highlander France !',
                'countdown_mode' => 'datetime',
                'messages' => '',
                'slideshow_display_s' => 8,
                'slideshow_fade_s' => 1,
            ])
            ->assertSessionHas('error');

        // Rien n'a été enregistré : le mode d'origine est intact.
        $this->assertSame('duration', $this->findOverlay()['countdown']['mode']);
    }

    public function test_l_overlay_affiche_decompte_equipes_messages_et_programme(): void
    {
        $this->seedProgramme([
            'welcome' => 'Bienvenue sur Highlander France !',
            'team_a' => 'TWICE',
            'team_b' => 'ChudsX6',
            'team_a_avatar' => 'https://example.com/twice.png',
            'team_b_avatar' => 'https://example.com/chuds.png',
            'messages' => [
                'Tous nos matchs sont disponibles en rediffusion sur notre chaîne YouTube',
                'Le stream commence à 21h',
            ],
            'slideshow' => ['display_s' => 10, 'fade_s' => 2],
            'countdown' => [
                'mode' => 'duration',
                'label' => 'Début dans',
                'duration_s' => 1800,
                'started_at' => time() - 60,
                'adjust_s' => 120,
                'zero_text' => 'EN DIRECT',
            ],
            'schedule' => [
                ['day' => 'Dimanche 11 Octobre', 'time' => '21h', 'label' => 'Finale Loser Bracket - Prem', 'team_a' => 'TWICE', 'team_b' => 'ChudsX6'],
                ['day' => 'Dimanche 18 Octobre', 'time' => '21h', 'label' => 'Demi-Finale - Open', 'team_a' => 'ZeroKen', 'team_b' => 'RUINATION'],
            ],
        ]);

        $response = $this->get('/programme-overlay/'.self::TOKEN);

        $response->assertOk()
            ->assertSee('Bienvenue sur Highlander France !')
            ->assertSee('Début dans')
            ->assertSee('TWICE')
            ->assertSee('ChudsX6')
            // Avatars des équipes du jour (URL importées depuis ETF2L).
            ->assertSee('https://example.com/twice.png')
            ->assertSee('https://example.com/chuds.png')
            ->assertSee('rediffusion sur notre chaîne YouTube')
            ->assertSee('Le stream commence à 21h')
            ->assertSee('Programmation de la semaine')
            ->assertSee('Dimanche 11 Octobre')
            ->assertSee('Finale Loser Bracket - Prem')
            ->assertSee('ZeroKen')
            ->assertSee('RUINATION');

        // Données du décompte et du diaporama passées au JavaScript.
        $html = (string) $response->getContent();
        $this->assertStringContainsString('data-mode="duration"', $html);
        $this->assertStringContainsString('data-adjust-s="120"', $html);
        $this->assertStringContainsString('data-display-s="10"', $html);
        $this->assertStringContainsString('data-fade-s="2"', $html);
        $this->assertStringContainsString('data-zero-text="EN DIRECT"', $html);
    }

    public function test_la_page_overlay_n_expose_rien_de_sensible(): void
    {
        $this->seedProgramme();

        $response = $this->get('/programme-overlay/'.self::TOKEN);
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('APP_KEY', (string) $response->getContent());
    }

    public function test_l_endpoint_de_version_renvoie_la_version_courante(): void
    {
        $this->seedProgramme();

        $this->get('/programme-overlay/'.self::TOKEN.'/version')
            ->assertOk()
            ->assertJson(['version' => (int) ($this->findOverlay()['version'])]);
    }

    public function test_l_ajustement_du_decompte_cumule_l_offset_et_bump_la_version(): void
    {
        $this->seedProgramme();
        $before = $this->findOverlay();

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/countdown', ['delta_s' => 60])
            ->assertSessionHas('success');

        $after = $this->findOverlay();
        $this->assertSame(60, $after['countdown']['adjust_s']);
        // La version est un time() (pattern des autres overlays) : deux
        // enregistrements dans la même seconde donnent la même valeur.
        $this->assertGreaterThanOrEqual((int) $before['version'], (int) $after['version']);

        // Négatif puis remise à zéro : l'offset retombe à 0.
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/countdown', ['delta_s' => -60])
            ->assertSessionHas('success');

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/countdown', ['reset' => '1'])
            ->assertSessionHas('success');

        $this->assertSame(0, $this->findOverlay()['countdown']['adjust_s']);
    }

    public function test_l_ajustement_refuse_un_delta_hors_bornes(): void
    {
        $this->seedProgramme();

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/countdown', ['delta_s' => 99999999])
            ->assertSessionHas('errors');

        $this->assertSame(0, $this->findOverlay()['countdown']['adjust_s']);
    }

    public function test_l_import_etf2l_depuis_un_cache_renvoie_le_match(): void
    {
        $this->seedEtf2lMatchCache(93233);

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/programme/etf2l-match?match_url=https://etf2l.org/matches/93233/')
            ->assertOk()
            ->assertJsonPath('match.id', 93233)
            ->assertJsonPath('match.teams.0.name', 'Les Baguettes')
            ->assertJsonPath('match.teams.0.avatar', 'https://example.com/lb.png')
            ->assertJsonPath('match.teams.1.name', 'Escouade 6');
    }

    public function test_les_prochains_matchs_de_la_base_arrivent_en_entrees_pretes(): void
    {
        // Dimanche suivant à 21h05, heure de Paris : toujours à venir.
        $sunday = new \DateTimeImmutable('next sunday 21:05', new \DateTimeZone('Europe/Paris'));

        DB::table('etf2l_matches')->insert([
            'match_id' => 95001,
            'team1_name' => 'Les Baguettes',
            'team2_name' => 'Escouade 6',
            'match_date' => $sunday->getTimestamp(),
            'competition_name' => 'ETF2L Highlander Season 40 - Division 1',
        ]);

        $response = $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/programme/upcoming');

        $response->assertOk();

        $match = $response->json('matches.0');
        $this->assertSame('Dimanche', substr((string) $match['day'], 0, 8));
        $this->assertSame('21h05', $match['time']);
        $this->assertSame('ETF2L Highlander Season 40 - Division 1', $match['label']);
        $this->assertSame('Les Baguettes', $match['team_a']);
        $this->assertSame('Escouade 6', $match['team_b']);
        $this->assertSame('https://etf2l.org/matches/95001/', $match['match_url']);
    }

    public function test_les_prochains_matchs_sans_table_peuplee_repondent_vide(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/programme/upcoming')
            ->assertOk()
            ->assertJson(['matches' => []]);
    }

    public function test_les_prochains_matchs_sont_interdits_aux_visiteurs(): void
    {
        $this->get('/admin/overlay/programme/upcoming')->assertForbidden();
    }

    public function test_l_import_etf2l_refuse_une_url_invalide(): void
    {
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/programme/etf2l-match?match_url=https://example.com/pas-un-match')
            ->assertOk()
            ->assertJsonPath('match', null)
            ->assertJsonPath('error', 'Lien de match ETF2L invalide : il doit ressembler à https://etf2l.org/matches/93233/.');
    }

    public function test_la_suppression_retire_l_overlay(): void
    {
        $this->seedProgramme();

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/delete')
            ->assertRedirect('/admin/overlay/programme');

        $this->assertNull((new ProgrammeRepository)->find(self::TOKEN));
        $this->get('/programme-overlay/'.self::TOKEN)->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedProgramme(array $overrides = []): void
    {
        (new ProgrammeRepository)->save(array_merge([
            'token' => self::TOKEN,
            'name' => 'Programme du dimanche',
            'welcome' => 'Bienvenue sur Highlander France !',
            'team_a' => '',
            'team_b' => '',
            'messages' => [],
            'slideshow' => ['display_s' => 8, 'fade_s' => 1],
            'countdown' => [
                'mode' => 'duration',
                'label' => 'Début dans',
                'duration_s' => 3600,
                'started_at' => time(),
                'adjust_s' => 0,
                'zero_text' => 'EN DIRECT',
            ],
            'schedule' => [],
            'created_at' => time() - 100,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function findOverlay(): array
    {
        $overlay = (new ProgrammeRepository)->find(self::TOKEN);
        $this->assertNotNull($overlay);

        return $overlay;
    }

    public function test_une_apostrophe_s_affiche_correctement_sans_double_echappement(): void
    {
        $this->seedProgramme([
            'messages' => ["Le compétitif t'intéresse ? Rendez-vous sur notre site"],
        ]);

        $response = $this->get('/programme-overlay/'.self::TOKEN);

        // assertSee échappe l'aiguille avec e() : il attend donc
        // l'apostrophe simple-échappée (&#039;) dans le HTML — pas le
        // double échappement (&amp;#039;) qui affichait « t&#039; » à
        // l'écran.
        $response->assertOk()->assertSee("Le compétitif t'intéresse ? Rendez-vous sur notre site");
        $this->assertStringNotContainsString('&amp;#039;', (string) $response->getContent());
    }

    public function test_les_entites_html_des_messages_sont_decodees_a_l_enregistrement(): void
    {
        $this->seedProgramme();

        // Données polluées par l'ancien double échappement : entité
        // « &#039; » collée ou ré-enregistrée depuis le formulaire.
        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->post('/admin/overlay/programme/'.self::TOKEN.'/update', [
                'name' => 'Programme du dimanche',
                'welcome' => 'Bienvenue sur Highlander France !',
                'countdown_mode' => 'duration',
                'countdown_duration_min' => 60,
                'messages' => 'Le compétitif t&#039;intéresse ?',
                'slideshow_display_s' => 8,
                'slideshow_fade_s' => 1,
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            ["Le compétitif t'intéresse ?"],
            $this->findOverlay()['messages']
        );
    }

    public function test_l_import_etf2l_decode_les_entites_des_noms_d_equipes(): void
    {
        $this->seedEtf2lMatchCache(93234, 'L&#039;Escouade 6');

        $this->withSession(['steamid' => '76561198012345678', 'is_caster' => true])
            ->get('/admin/overlay/programme/etf2l-match?match_url=https://etf2l.org/matches/93234/')
            ->assertOk()
            ->assertJsonPath('match.teams.1.name', "L'Escouade 6");
    }

    /**
     * Amorce le cache ETF2L d'un match (conso modèle SeriesAdminTest) :
     * le service répond depuis le cache, sans appel HTTP réel.
     */
    private function seedEtf2lMatchCache(int $matchId, string $team2Name = 'Escouade 6'): void
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
                        'name' => $team2Name,
                        'country' => 'France',
                        'steam' => ['avatar' => 'https://example.com/e6.png'],
                    ],
                    'competition' => ['id' => 1060, 'name' => 'Highlander Season 36: High Playoffs', 'type' => 'Highlander'],
                    'maps' => ['pl_upward_f10', 'koth_product_final'],
                ],
            ], JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ]);
    }
}
