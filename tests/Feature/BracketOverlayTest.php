<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BracketRepository;
use App\Models\SeriesRepository;
use App\Services\SeriesScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Overlay OBS bracket / classement : pages publiques par token (source
 * navigateur OBS, fond transparent) et endpoint de version interrogé par la
 * page pour se rafraîchir. Le match « EN DIRECT » peut être attaché à une
 * série : son score affiché suit alors la série, et la version de l'overlay
 * suit aussi celle de la série. Aucune donnée sensible : noms d'équipes et
 * scores de matchs publics.
 */
class BracketOverlayTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaa';

    private const SERIES_TOKEN = 'bbbbbbbbbbbbbbbb';

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Dossier de données dédié aux tests : rien ne pollue le vrai
        // storage/app/hlfr et tout est nettoyé en tearDown.
        $this->dataDir = storage_path('app/hlfr_bracket_overlay_test');
        config(['hlfr.data_dir' => $this->dataDir]);

        // Le remplissage assisté ETF2L de l'éditeur est servi depuis le
        // cache : amorce vide pour rester sans appel HTTP réel.
        $this->seedEtf2lCompetitionsCache();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataDir.'/brackets/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataDir.'/brackets');
        foreach (glob($this->dataDir.'/series/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataDir.'/series');
        @rmdir($this->dataDir);

        parent::tearDown();
    }

    public function test_un_token_inconnu_renvoie_404(): void
    {
        $this->get('/bracket-overlay/'.self::TOKEN)->assertNotFound();
        $this->get('/bracket-overlay/'.self::TOKEN.'/version')->assertNotFound();
    }

    public function test_le_bracket_affiche_equipes_scores_et_le_match_live(): void
    {
        $this->seedBracket([
            'columns' => [
                [
                    'id' => 'c1', 'label' => 'Demi-finales', 'lane' => 'upper',
                    'matches' => [
                        [
                            'id' => 'm1',
                            'teams' => [
                                ['name' => 'DD14', 'avatar' => '', 'country' => 'France', 'score' => '6'],
                                ['name' => 'AKATSUKI', 'avatar' => '', 'country' => '', 'score' => '0'],
                            ],
                            'series_token' => null, 'manual_scores' => false,
                        ],
                        [
                            'id' => 'm2',
                            'teams' => [
                                ['name' => '', 'avatar' => '', 'country' => '', 'score' => ''],
                                ['name' => 'Équipe mystère', 'avatar' => '', 'country' => '', 'score' => ''],
                            ],
                            'series_token' => null, 'manual_scores' => false,
                        ],
                    ],
                ],
                [
                    'id' => 'c2', 'label' => 'Grand Final', 'lane' => 'final',
                    'matches' => [
                        [
                            'id' => 'm3',
                            'teams' => [
                                ['name' => 'DD14', 'avatar' => '', 'country' => '', 'score' => ''],
                                ['name' => 'AKATSUKI', 'avatar' => '', 'country' => '', 'score' => ''],
                            ],
                            'series_token' => null, 'manual_scores' => false,
                        ],
                    ],
                ],
            ],
            'live' => ['column' => 'c2', 'match' => 'm3'],
        ]);

        $response = $this->get('/bracket-overlay/'.self::TOKEN);
        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('Division 1', $html);
        $this->assertStringContainsString('Playoffs', $html);
        $this->assertStringContainsString('DD14', $html);
        $this->assertStringContainsString('AKATSUKI', $html);
        $this->assertStringContainsString('Grand Final', $html);
        // Le badge « EN DIRECT ! » est posé par le JS : sa donnée est dans le JSON.
        $this->assertStringContainsString('"live":true', $html);
        // Le polling de version vit dans overlay_bracket.js, référencé par la page.
        $this->assertStringContainsString('_js/overlay_bracket.js', $html);
    }

    public function test_le_score_du_match_live_suit_la_serie_attachee(): void
    {
        $this->seedSeries();
        $this->seedBracket([
            'columns' => [
                [
                    'id' => 'c1', 'label' => 'Grand Final', 'lane' => 'final',
                    'matches' => [
                        [
                            'id' => 'm1',
                            'teams' => [
                                ['name' => 'Les Baguettes', 'avatar' => '', 'country' => '', 'score' => '0'],
                                ['name' => 'Escouade 6', 'avatar' => '', 'country' => '', 'score' => '0'],
                            ],
                            'series_token' => self::SERIES_TOKEN, 'manual_scores' => false,
                        ],
                    ],
                ],
            ],
            'live' => ['column' => 'c1', 'match' => 'm1'],
        ]);

        // Score saisi dans la case (0-0) remplacé par le score live de la
        // série (3-3 en points de ligue ETF2L : upward remportée par deux
        // logs rouges + koth bleu, une map gagnée vaut 3 points, cf.
        // seedSeries — le bracket affiche le score global de la série).
        $html = (string) $this->get('/bracket-overlay/'.self::TOKEN)->getContent();
        $this->assertStringContainsString('"score":"3"', $html);
        $this->assertStringNotContainsString('"score":"0"', $html);
    }

    public function test_le_score_manuel_force_prime_sur_la_serie(): void
    {
        $this->seedSeries();
        $this->seedBracket([
            'columns' => [
                [
                    'id' => 'c1', 'label' => 'Grand Final', 'lane' => 'final',
                    'matches' => [
                        [
                            'id' => 'm1',
                            'teams' => [
                                ['name' => 'Les Baguettes', 'avatar' => '', 'country' => '', 'score' => '7'],
                                ['name' => 'Escouade 6', 'avatar' => '', 'country' => '', 'score' => '4'],
                            ],
                            'series_token' => self::SERIES_TOKEN, 'manual_scores' => true,
                        ],
                    ],
                ],
            ],
            'live' => ['column' => 'c1', 'match' => 'm1'],
        ]);

        $html = (string) $this->get('/bracket-overlay/'.self::TOKEN)->getContent();
        $this->assertStringContainsString('"score":"7"', $html);
        $this->assertStringContainsString('"score":"4"', $html);
    }

    public function test_la_version_suivie_celle_de_la_serie_attachee_au_match_live(): void
    {
        $this->seedSeries();
        $this->seedBracket([
            'columns' => [
                [
                    'id' => 'c1', 'label' => 'Grand Final', 'lane' => 'final',
                    'matches' => [
                        [
                            'id' => 'm1',
                            'teams' => [
                                ['name' => 'Les Baguettes', 'avatar' => '', 'country' => '', 'score' => ''],
                                ['name' => 'Escouade 6', 'avatar' => '', 'country' => '', 'score' => ''],
                            ],
                            'series_token' => self::SERIES_TOKEN, 'manual_scores' => false,
                        ],
                    ],
                ],
            ],
            'live' => ['column' => 'c1', 'match' => 'm1'],
        ]);

        // La version de la série (time() + 500) est plus récente que celle
        // du bracket : l'overlay se rafraîchit dès qu'un point est marqué.
        $response = $this->get('/bracket-overlay/'.self::TOKEN.'/version');
        $response->assertOk();
        $this->assertGreaterThan(time(), (int) $response->json('version'));
    }

    public function test_le_classement_affiche_rangs_et_medailles_sans_top_x(): void
    {
        $this->seedBracket([
            'kind' => 'table',
            'rows' => [
                ['name' => 'DD14', 'avatar' => '', 'country' => 'France', 'played' => 10, 'won' => 9, 'lost' => 1, 'score' => 27, 'penalty' => 0],
                ['name' => 'AKATSUKI', 'avatar' => '', 'country' => 'DominicanRepublic', 'played' => 10, 'won' => 7, 'lost' => 3, 'score' => 21, 'penalty' => 2],
                ['name' => 'RUINATION', 'avatar' => '', 'country' => 'Europe', 'played' => 10, 'won' => 5, 'lost' => 5, 'score' => 15, 'penalty' => 0],
            ],
        ]);

        $response = $this->get('/bracket-overlay/'.self::TOKEN);
        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('DD14', $html);
        $this->assertStringContainsString('AKATSUKI', $html);
        $this->assertStringContainsString('Pén', $html);
        // Plus de surlignage « top X » : seul le top 3 médaille (CSS)
        // distingue les lignes.
        $this->assertStringNotContainsString('qualified', $html);
        $this->assertStringNotContainsString('flags/', $html);
        $this->assertStringNotContainsString('class="flag"', $html);
    }

    public function test_le_classement_sans_penalite_masque_la_colonne(): void
    {
        $this->seedBracket([
            'kind' => 'table',
            'rows' => [
                ['name' => 'DD14', 'avatar' => '', 'country' => 'France', 'played' => 10, 'won' => 9, 'lost' => 1, 'score' => 27, 'penalty' => 0],
            ],
        ]);

        $html = (string) $this->get('/bracket-overlay/'.self::TOKEN)->getContent();
        $this->assertStringNotContainsString('Pén', $html);
        $this->assertStringNotContainsString('qualified', $html);
    }

    public function test_la_page_editeur_admin_rend_l_editeur_visuel_bracket(): void
    {
        $this->seedBracket([
            'columns' => [
                [
                    'id' => 'c1', 'label' => 'Demi-finales', 'lane' => 'upper',
                    'matches' => [
                        [
                            'id' => 'm1',
                            'teams' => [
                                ['name' => 'DD14', 'avatar' => '', 'country' => 'France', 'score' => '6'],
                                ['name' => 'AKATSUKI', 'avatar' => '', 'country' => '', 'score' => '0'],
                            ],
                            'series_token' => null, 'manual_scores' => false,
                        ],
                    ],
                ],
            ],
            'live' => ['column' => 'c1', 'match' => 'm1'],
        ]);

        $response = $this->withSession(['steamid' => '76561198012345678', 'is_admin' => true])
            ->get('/admin/overlay/bracket/'.self::TOKEN);
        $response->assertOk();

        $html = (string) $response->getContent();
        // Éditeur visuel : le bracket est amorcé en JSON et rendu par
        // admin_bracket_editor.js sur le moteur partagé bracket_layout.js.
        $this->assertStringContainsString('"label":"Demi-finales"', $html);
        $this->assertStringContainsString('admin_bracket_editor.js', $html);
        $this->assertStringContainsString('bracket_layout.js', $html);
        // Générateur de squelette et désignation du match « EN DIRECT ».
        $this->assertStringContainsString('Générer le squelette', $html);
        $this->assertStringContainsString('Système suisse', $html);
        $this->assertStringContainsString('EN DIRECT', $html);
        $this->assertStringContainsString('/bracket-overlay/'.self::TOKEN, $html);
    }

    public function test_la_page_editeur_admin_rend_l_editeur_visuel_classement(): void
    {
        $this->seedBracket([
            'kind' => 'table',
            'rows' => [
                ['name' => 'DD14', 'avatar' => '', 'country' => 'France', 'played' => 10, 'won' => 9, 'lost' => 1, 'score' => 27, 'penalty' => 0],
            ],
        ]);

        $response = $this->withSession(['steamid' => '76561198012345678', 'is_admin' => true])
            ->get('/admin/overlay/bracket/'.self::TOKEN);
        $response->assertOk();

        $html = (string) $response->getContent();
        // Éditeur visuel : le classement est amorcé en JSON, l'aperçu est
        // rendu par admin_table_editor.js.
        $this->assertStringContainsString('"name":"DD14"', $html);
        $this->assertStringContainsString('admin_table_editor.js', $html);
        $this->assertStringContainsString('Ajouter une ligne', $html);
        $this->assertStringContainsString('/bracket-overlay/'.self::TOKEN, $html);
    }

    public function test_l_enregistrement_depuis_l_editeur_visuel_conserve_le_schema_post_classement(): void
    {
        $this->seedBracket(['kind' => 'table', 'rows' => []]);

        // Schéma sérialisé par l'éditeur visuel (admin_table_editor.js) :
        // identique au formulaire historique, le contrôleur n'a pas changé.
        $this->withSession(['steamid' => '76561198012345678', 'is_admin' => true])
            ->post('/admin/overlay/bracket/'.self::TOKEN.'/update', [
                'eyebrow' => 'ETF2L Highlander Saison 36',
                'title' => 'Division 2',
                'accent' => 'Poule',
                'rows' => [
                    [
                        'name' => 'DD14', 'avatar' => '', 'country' => 'France',
                        'played' => 10, 'won' => 9, 'lost' => 1, 'score' => 27, 'penalty' => 0,
                    ],
                    [
                        'name' => 'AKATSUKI', 'avatar' => '', 'country' => '',
                        'played' => 10, 'won' => 7, 'lost' => 3, 'score' => 21, 'penalty' => 2,
                    ],
                ],
            ])
            ->assertRedirect();

        $bracket = (new BracketRepository)->find(self::TOKEN);
        $this->assertSame('table', $bracket['kind']);
        $this->assertArrayNotHasKey('top_x', $bracket);
        $this->assertSame('DD14', $bracket['rows'][0]['name']);
        $this->assertSame(27, $bracket['rows'][0]['score']);
        $this->assertSame(2, $bracket['rows'][1]['penalty']);
    }

    public function test_l_enregistrement_depuis_l_editeur_visuel_conserve_le_schema_post(): void
    {
        $this->seedBracket(['columns' => [], 'live' => null]);

        // Schéma sérialisé par l'éditeur visuel (admin_bracket_editor.js) :
        // identique au formulaire historique, le contrôleur n'a pas changé.
        // La colonne finale porte la grande finale et une case « 3è place »
        // avec son titre propre (label de case).
        $this->withSession(['steamid' => '76561198012345678', 'is_admin' => true])
            ->post('/admin/overlay/bracket/'.self::TOKEN.'/update', [
                'eyebrow' => 'ETF2L Highlander Saison 36',
                'title' => 'Division 1',
                'accent' => 'Playoffs',
                'columns' => [
                    [
                        'id' => 'c1', 'label' => 'WB R1', 'lane' => 'upper',
                        'matches' => [
                            [
                                'id' => 'm1',
                                'team1_name' => 'DD14', 'team1_avatar' => '', 'team1_country' => 'France', 'team1_score' => '6',
                                'team2_name' => 'AKATSUKI', 'team2_avatar' => '', 'team2_country' => '', 'team2_score' => '0',
                                'series_token' => '', 'manual_scores' => '0', 'label' => '',
                            ],
                        ],
                    ],
                    [
                        'id' => 'c2', 'label' => 'Grand Final', 'lane' => 'final', 'flat' => '0',
                        'matches' => [
                            [
                                'id' => 'm2',
                                'team1_name' => 'DD14', 'team1_avatar' => '', 'team1_country' => '', 'team1_score' => '',
                                'team2_name' => 'AKATSUKI', 'team2_avatar' => '', 'team2_country' => '', 'team2_score' => '',
                                'series_token' => '', 'manual_scores' => '0', 'label' => '',
                            ],
                            [
                                'id' => 'm3',
                                'team1_name' => 'TR9s', 'team1_avatar' => '', 'team1_country' => '', 'team1_score' => '',
                                'team2_name' => 'RUINATION', 'team2_avatar' => '', 'team2_country' => '', 'team2_score' => '',
                                'series_token' => '', 'manual_scores' => '0', 'label' => 'Match de 3è place',
                            ],
                        ],
                    ],
                ],
                'live_match' => 'c1:m1',
            ])
            ->assertRedirect();

        $bracket = (new BracketRepository)->find(self::TOKEN);
        $this->assertSame('WB R1', $bracket['columns'][0]['label']);
        $this->assertSame('upper', $bracket['columns'][0]['lane']);
        $this->assertFalse($bracket['columns'][0]['flat']);
        $this->assertSame('DD14', $bracket['columns'][0]['matches'][0]['teams'][0]['name']);
        $this->assertSame('6', $bracket['columns'][0]['matches'][0]['teams'][0]['score']);
        $this->assertSame(['column' => 'c1', 'match' => 'm1'], $bracket['live']);

        // Titre de la grande finale : à défaut de label de case, le titre
        // de la colonne ; la case « 3è place » garde son label propre.
        $final = $bracket['columns'][1]['matches'];
        $this->assertSame('', $final[0]['label']);
        $this->assertSame('Match de 3è place', $final[1]['label']);
        $this->assertSame('TR9s', $final[1]['teams'][0]['name']);
    }

    public function test_le_bracket_affiche_le_match_de_troisieme_place_sous_la_finale(): void
    {
        $this->seedBracket([
            'columns' => [
                [
                    'id' => 'c1', 'label' => 'Demi-finales', 'lane' => 'upper',
                    'matches' => [
                        [
                            'id' => 'm1',
                            'teams' => [
                                ['name' => 'Distemper', 'avatar' => '', 'country' => '', 'score' => '9'],
                                ['name' => 'TR9s', 'avatar' => '', 'country' => '', 'score' => '0'],
                            ],
                            'label' => '', 'series_token' => null, 'manual_scores' => false,
                        ],
                        [
                            'id' => 'm2',
                            'teams' => [
                                ['name' => 'ZeroKen', 'avatar' => '', 'country' => '', 'score' => '1'],
                                ['name' => 'RUINATION', 'avatar' => '', 'country' => '', 'score' => '5'],
                            ],
                            'label' => '', 'series_token' => null, 'manual_scores' => false,
                        ],
                    ],
                ],
                [
                    'id' => 'c2', 'label' => 'Final', 'lane' => 'final',
                    'matches' => [
                        [
                            'id' => 'm3',
                            'teams' => [
                                ['name' => 'Distemper', 'avatar' => '', 'country' => '', 'score' => ''],
                                ['name' => 'RUINATION', 'avatar' => '', 'country' => '', 'score' => ''],
                            ],
                            'label' => '', 'series_token' => null, 'manual_scores' => false,
                        ],
                        [
                            'id' => 'm4',
                            'teams' => [
                                ['name' => 'TR9s', 'avatar' => '', 'country' => '', 'score' => ''],
                                ['name' => 'ZeroKen', 'avatar' => '', 'country' => '', 'score' => ''],
                            ],
                            'label' => 'Match de 3è place', 'series_token' => null, 'manual_scores' => false,
                        ],
                    ],
                ],
            ],
            'live' => null,
        ]);

        $html = (string) $this->get('/bracket-overlay/'.self::TOKEN)->getContent();

        // Le payload porte le label de la case « 3è place » : la carte est
        // posée sous la grande finale par overlay_bracket.js (moteur partagé).
        $this->assertStringContainsString('"label":"Match de 3è place"', $html);
        $this->assertStringContainsString('"label":""', $html);
        $this->assertStringContainsString('ZeroKen', $html);
    }

    public function test_le_format_suisse_rend_des_rounds_plats_sans_connecteurs(): void
    {
        // Squelette « système suisse » : 6 équipes → ceil(log2(6)) = 3
        // rounds de 3 cases, colonnes « à plat » (flat) — le moteur de
        // layout (bracket_layout.js) ne câble alors aucun connecteur
        // entre les rounds, les paires étant indépendantes.
        $teams = ['DD14', 'AKATSUKI', 'RUINATION', 'ZeroKen', 'TR9s', 'Distemper'];
        $this->seedBracket([
            'columns' => [
                $this->swissRound('r1', 'Round 1', $teams),
                $this->swissRound('r2', 'Round 2', $teams),
            ],
            'live' => null,
        ]);

        $html = (string) $this->get('/bracket-overlay/'.self::TOKEN)->getContent();
        $this->assertStringContainsString('"flat":true', $html);
        $this->assertStringContainsString('Round 1', $html);
        $this->assertStringContainsString('Round 2', $html);
        $this->assertStringContainsString('AKATSUKI', $html);
    }

    public function test_l_enregistrement_depuis_l_editeur_visuel_persiste_les_colonnes_plates(): void
    {
        $this->seedBracket(['columns' => [], 'live' => null]);

        // Schéma sérialisé par l'éditeur visuel (admin_bracket_editor.js)
        // pour un squelette suisse : flat = 1 sur chaque colonne de round.
        $this->withSession(['steamid' => '76561198012345678', 'is_admin' => true])
            ->post('/admin/overlay/bracket/'.self::TOKEN.'/update', [
                'eyebrow' => 'Swiss Round Robin',
                'title' => 'Division 1',
                'accent' => 'Swiss',
                'columns' => [
                    [
                        'id' => 'r1', 'label' => 'Round 1', 'lane' => 'upper', 'flat' => '1',
                        'matches' => [
                            [
                                'id' => 'm1',
                                'team1_name' => 'DD14', 'team1_avatar' => '', 'team1_country' => '', 'team1_score' => '',
                                'team2_name' => 'AKATSUKI', 'team2_avatar' => '', 'team2_country' => '', 'team2_score' => '',
                                'series_token' => '', 'manual_scores' => '0', 'label' => '',
                            ],
                        ],
                    ],
                ],
                'live_match' => '',
            ])
            ->assertRedirect();

        $bracket = (new BracketRepository)->find(self::TOKEN);
        $this->assertTrue($bracket['columns'][0]['flat']);
        $this->assertSame('Round 1', $bracket['columns'][0]['label']);
    }

    // ─── Amorçage ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedBracket(array $overrides): void
    {
        (new BracketRepository)->save(array_merge([
            'token' => self::TOKEN,
            'kind' => 'bracket',
            'eyebrow' => 'ETF2L Highlander Saison 36',
            'title' => 'Division 1',
            'accent' => 'Playoffs',
            'columns' => [],
            'live' => null,
            'created_at' => time() - 100,
        ], $overrides));
    }

    /**
     * Colonne de round suisse : cases à plat, deux équipes nommées par
     * case, pas de série suivie.
     *
     * @param  array<int, string>  $teamNames
     * @return array<string, mixed>
     */
    private function swissRound(string $id, string $label, array $teamNames): array
    {
        $matches = [];
        foreach (array_chunk($teamNames, 2) as $index => $pair) {
            $matches[] = [
                'id' => $id.'m'.($index + 1),
                'teams' => [
                    ['name' => $pair[0], 'avatar' => '', 'country' => '', 'score' => ''],
                    ['name' => $pair[1] ?? '', 'avatar' => '', 'country' => '', 'score' => ''],
                ],
                'label' => '',
                'series_token' => null,
                'manual_scores' => false,
            ];
        }

        return [
            'id' => $id, 'label' => $label, 'lane' => 'upper', 'flat' => true,
            'matches' => $matches,
        ];
    }

    private function seedSeries(): void
    {
        $events = [
            ['id' => 'e1', 'type' => 'log', 'source' => 'logstf', 'log_id' => 100001, 'map' => 'pl_upward_f10', 'winner' => 'red', 'scores' => ['red' => 2, 'blue' => 0], 'title' => 'HLFR : match', 'at' => time()],
            ['id' => 'e2', 'type' => 'log', 'source' => 'logstf', 'log_id' => 100002, 'map' => 'pl_upward_f10', 'winner' => 'red', 'scores' => ['red' => 2, 'blue' => 0], 'title' => 'HLFR : match', 'at' => time()],
            ['id' => 'e3', 'type' => 'log', 'source' => 'logstf', 'log_id' => 100003, 'map' => 'koth_product_final', 'winner' => 'blue', 'scores' => ['red' => 0, 'blue' => 3], 'title' => 'HLFR : match', 'at' => time()],
        ];

        (new SeriesRepository)->save([
            'token' => self::SERIES_TOKEN,
            'title' => 'Finale playoffs — Les Baguettes vs Escouade 6',
            'format' => 'bo5',
            'wins_needed' => 3,
            'status' => 'live',
            'started_at' => time() - 3600,
            'created_at' => time() - 4000,
            'version' => time() + 500,
            'teams' => [
                'red' => ['name' => 'Les Baguettes', 'players' => ['76561198000000001']],
                'blue' => ['name' => 'Escouade 6', 'players' => ['76561198000000011']],
            ],
            'maps' => [
                ['name' => 'pl_upward_f10', 'mode' => SeriesScoreService::MODE_DOUBLE],
                ['name' => 'koth_product_final', 'mode' => SeriesScoreService::MODE_SINGLE],
            ],
            'journal' => $events,
            'seen_logs' => [],
        ]);
    }
}
