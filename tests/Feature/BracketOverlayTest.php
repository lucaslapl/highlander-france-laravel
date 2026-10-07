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
        // série (1-1 : upward remportée par deux logs rouges + koth bleu,
        // cf. seedSeries — le score de série compte les maps gagnées).
        $html = (string) $this->get('/bracket-overlay/'.self::TOKEN)->getContent();
        $this->assertStringContainsString('"score":"1"', $html);
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

    public function test_le_classement_affiche_rangs_et_top_x_sans_drapeaux(): void
    {
        $this->seedBracket([
            'kind' => 'table',
            'rows' => [
                ['name' => 'DD14', 'avatar' => '', 'country' => 'France', 'played' => 10, 'won' => 9, 'lost' => 1, 'score' => 27, 'penalty' => 0],
                ['name' => 'AKATSUKI', 'avatar' => '', 'country' => 'DominicanRepublic', 'played' => 10, 'won' => 7, 'lost' => 3, 'score' => 21, 'penalty' => 2],
                ['name' => 'RUINATION', 'avatar' => '', 'country' => 'Europe', 'played' => 10, 'won' => 5, 'lost' => 5, 'score' => 15, 'penalty' => 0],
            ],
            'top_x' => 2,
        ]);

        $response = $this->get('/bracket-overlay/'.self::TOKEN);
        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('DD14', $html);
        $this->assertStringContainsString('AKATSUKI', $html);
        $this->assertStringContainsString('Pén', $html);
        $this->assertStringContainsString('class="qualified"', $html);
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
            'top_x' => 0,
        ]);

        $html = (string) $this->get('/bracket-overlay/'.self::TOKEN)->getContent();
        $this->assertStringNotContainsString('Pén', $html);
        $this->assertStringNotContainsString('class="qualified"', $html);
    }

    public function test_la_page_editeur_admin_rend_le_formulaire_bracket(): void
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
        $this->assertStringContainsString('Colonnes du bracket', $html);
        $this->assertStringContainsString('Demi-finales', $html);
        $this->assertStringContainsString('EN DIRECT', $html);
        $this->assertStringContainsString('/bracket-overlay/'.self::TOKEN, $html);
    }

    public function test_la_page_editeur_admin_rend_le_formulaire_classement(): void
    {
        $this->seedBracket([
            'kind' => 'table',
            'rows' => [
                ['name' => 'DD14', 'avatar' => '', 'country' => 'France', 'played' => 10, 'won' => 9, 'lost' => 1, 'score' => 27, 'penalty' => 0],
            ],
            'top_x' => 1,
        ]);

        $response = $this->withSession(['steamid' => '76561198012345678', 'is_admin' => true])
            ->get('/admin/overlay/bracket/'.self::TOKEN);
        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('Lignes du classement', $html);
        $this->assertStringContainsString('DD14', $html);
        $this->assertStringContainsString('Top X surligné', $html);
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
