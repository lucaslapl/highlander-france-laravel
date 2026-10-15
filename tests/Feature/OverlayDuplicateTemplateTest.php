<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BracketRepository;
use App\Models\PickBanRepository;
use App\Models\ProgrammeRepository;
use App\Models\RostersRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Duplication et « Modèle par défaut » des outils overlay générables
 * (Bracket, Pick/Ban, Rosters, Programme — l'outil Overlay Logs et le
 * suivi des scores en sont exclus). La duplication est une copie
 * intégrale du payload sous un nouveau token : l'original reste
 * intact et la copie n'hérite pas du label « modèle par défaut ». Un
 * seul modèle par outil : le bouton « Dupliquer le modèle » de la zone
 * de création reprend l'overlay marqué, le bouton « Définir comme
 * modèle » d'une autre entrée retire le label au précédent porteur.
 */
class OverlayDuplicateTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_A = 'aaaaaaaaaaaaaaaa';

    private const TOKEN_B = 'bbbbbbbbbbbbbbbb';

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Dossier de données dédié aux tests : rien ne pollue le vrai
        // storage/app/hlfr et tout est nettoyé en tearDown.
        $this->dataDir = storage_path('app/hlfr_overlay_duplicate_test');
        config(['hlfr.data_dir' => $this->dataDir]);
    }

    protected function tearDown(): void
    {
        foreach (['brackets', 'pickbans', 'rosters', 'programmes'] as $dir) {
            foreach (glob($this->dataDir.'/'.$dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dataDir.'/'.$dir);
        }
        @rmdir($this->dataDir);

        parent::tearDown();
    }

    public static function outils(): array
    {
        return [
            'bracket' => ['bracket'],
            'pickban' => ['pickban'],
            'rosters' => ['rosters'],
            'programme' => ['programme'],
        ];
    }

    #[DataProvider('outils')]
    public function test_la_duplication_est_une_copie_integrale_sous_un_nouveau_token(string $outil): void
    {
        $this->seedOverlay($outil, self::TOKEN_A, 'Division 1');
        $this->seedOverlay($outil, self::TOKEN_B, 'Autre', time() - 50);

        $response = $this->withSession(['steamid' => '76561198012345678', 'is_admin' => true])
            ->post('/admin/overlay/'.$outil.'/'.self::TOKEN_A.'/duplicate');
        $response->assertRedirect();

        // La redirection ouvre la copie dans l'éditeur, comme la création.
        $copyToken = $this->redirectToken((string) $response->headers->get('Location'));
        $this->assertNotSame(self::TOKEN_A, $copyToken);

        $copy = $this->find($outil, $copyToken);
        $original = $this->find($outil, self::TOKEN_A);
        $this->assertNotNull($copy);
        $this->assertNotNull($original);

        // Copie intégrale : seul l'habillage de persistance diffère, et
        // le label « modèle par défaut » ne suit pas.
        unset($copy['token'], $copy['created_at'], $copy['version'], $copy['updated_at']);
        unset($original['token'], $original['created_at'], $original['version'], $original['updated_at']);
        $this->assertEquals($original, $copy);

        // L'index porte trois entrées : l'original, l'autre overlay, la copie.
        $this->assertCount(3, $this->all($outil));
    }

    #[DataProvider('outils')]
    public function test_le_label_modele_par_defaut_est_unique_et_alimente_le_bouton_de_creation(string $outil): void
    {
        $this->seedOverlay($outil, self::TOKEN_A, 'Division 1');
        $this->seedOverlay($outil, self::TOKEN_B, 'Division 2', time() - 50);

        $session = ['steamid' => '76561198012345678', 'is_admin' => true];

        $this->withSession($session)->post('/admin/overlay/'.$outil.'/'.self::TOKEN_A.'/template')->assertRedirect();
        $this->assertTrue((bool) ($this->find($outil, self::TOKEN_A)['is_template'] ?? false));

        // Marquer B retire le label à A : un seul modèle par outil.
        $this->withSession($session)->post('/admin/overlay/'.$outil.'/'.self::TOKEN_B.'/template')->assertRedirect();
        $this->assertArrayNotHasKey('is_template', $this->find($outil, self::TOKEN_A));
        $this->assertTrue((bool) ($this->find($outil, self::TOKEN_B)['is_template'] ?? false));

        // La liste affiche le badge sur B, le bouton « Définir comme
        // modèle » sur A, et le bouton « Dupliquer le modèle » de la
        // création pointe sur B.
        $html = (string) $this->withSession($session)->get('/admin/overlay/'.$outil)->getContent();
        $this->assertSame(1, substr_count($html, '<span class="badge badge-mixer">Modèle par défaut</span>'));
        $this->assertStringContainsString('action="/admin/overlay/'.$outil.'/'.self::TOKEN_A.'/template"', $html);
        $this->assertStringContainsString('action="/admin/overlay/'.$outil.'/'.self::TOKEN_B.'/duplicate"', $html);
        $this->assertStringContainsString('Dupliquer le modèle par défaut', $html);
    }

    #[DataProvider('outils')]
    public function test_un_token_inconnu_renvoie_404(string $outil): void
    {
        $session = ['steamid' => '76561198012345678', 'is_admin' => true];

        $this->withSession($session)->post('/admin/overlay/'.$outil.'/'.self::TOKEN_A.'/duplicate')->assertNotFound();
        $this->withSession($session)->post('/admin/overlay/'.$outil.'/'.self::TOKEN_A.'/template')->assertNotFound();
    }

    #[DataProvider('outils')]
    public function test_les_routes_sont_interdites_aux_visiteurs(string $outil): void
    {
        $this->post('/admin/overlay/'.$outil.'/'.self::TOKEN_A.'/duplicate')->assertForbidden();
        $this->post('/admin/overlay/'.$outil.'/'.self::TOKEN_A.'/template')->assertForbidden();
    }

    // ─── Amorçage ────────────────────────────────────────────────────────

    /**
     * Amorce un overlay du type de l'outil avec un contenu représentatif
     * (les données copiées doivent survivre à la duplication).
     */
    private function seedOverlay(string $outil, string $token, string $title, ?int $createdAt = null): void
    {
        $payload = match ($outil) {
            'bracket' => $this->seedBracket($title),
            'pickban' => $this->seedPickBan($title),
            'rosters' => $this->seedRosters($title),
            default => $this->seedProgramme($title),
        };

        $payload['token'] = $token;
        $payload['created_at'] = $createdAt ?? time() - 100;

        $this->save($outil, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedBracket(string $title): array
    {
        return [
            'kind' => 'bracket',
            'eyebrow' => 'ETF2L Highlander Saison 36',
            'title' => $title,
            'accent' => 'Playoffs',
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
            'live' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedPickBan(string $title): array
    {
        return [
            'format' => '3_3',
            'eyebrow' => 'ETF2L Highlander Saison 36',
            'title' => $title,
            'teams' => [
                'a' => ['name' => 'DD14', 'avatar' => ''],
                'b' => ['name' => 'AKATSUKI', 'avatar' => ''],
            ],
            'cards' => [
                ['map' => 'koth_product_final', 'image' => '', 'action' => 'ban', 'team' => 'a'],
                ['map' => 'pl_upward_f10', 'image' => '', 'action' => 'ban', 'team' => 'b'],
                ['map' => 'cp_process_final', 'image' => '', 'action' => 'pick', 'team' => 'a'],
                ['map' => 'cp_gullywash_final', 'image' => '', 'action' => 'pick', 'team' => 'b'],
                ['map' => 'cp_snakewater_final', 'image' => '', 'action' => 'ban', 'team' => 'a'],
                ['map' => 'koth_clearcut_b15', 'image' => '', 'action' => 'pick', 'team' => 'b'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedRosters(string $title): array
    {
        return [
            'format' => 'hl',
            'eyebrow' => 'ETF2L Highlander Saison 40',
            'title' => $title,
            'displayed' => 'a',
            'replay_enter' => false,
            'teams' => [
                'a' => ['name' => 'DD14', 'avatar' => ''],
                'b' => ['name' => 'AKATSUKI', 'avatar' => ''],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedProgramme(string $name): array
    {
        return [
            'name' => $name,
            'welcome' => 'Bienvenue sur Highlander France !',
            'team_a' => 'DD14',
            'team_b' => 'AKATSUKI',
            'team_a_avatar' => '',
            'team_b_avatar' => '',
            'messages' => ['Tous nos matchs sont disponibles en rediffusion'],
            'slideshow' => ['display_s' => 8, 'fade_s' => 1],
            'countdown' => [
                'mode' => 'duration', 'label' => 'Début dans', 'duration_s' => 3600,
                'started_at' => time(), 'adjust_s' => 0, 'zero_text' => 'EN DIRECT',
            ],
            'schedule' => [],
        ];
    }

    // ─── Accès aux dépôts de l'outil ─────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(string $outil): array
    {
        return match ($outil) {
            'bracket' => (new BracketRepository)->all(),
            'pickban' => (new PickBanRepository)->all(),
            'rosters' => (new RostersRepository)->all(),
            default => (new ProgrammeRepository)->all(),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $outil, string $token): ?array
    {
        return match ($outil) {
            'bracket' => (new BracketRepository)->find($token),
            'pickban' => (new PickBanRepository)->find($token),
            'rosters' => (new RostersRepository)->find($token),
            default => (new ProgrammeRepository)->find($token),
        };
    }

    /**
     * Persiste un payload via le dépôt de l'outil (ProgrammeRepository
     * signale l'échec d'écriture par false, les autres persistent
     * silencieusement comme en production).
     *
     * @param  array<string, mixed>  $payload
     */
    private function save(string $outil, array $payload): void
    {
        $saved = match ($outil) {
            'bracket' => ((new BracketRepository)->save($payload) ?? true),
            'pickban' => ((new PickBanRepository)->save($payload) ?? true),
            'rosters' => ((new RostersRepository)->save($payload) ?? true),
            default => (new ProgrammeRepository)->save($payload),
        };

        $this->assertTrue($saved);
    }

    /**
     * Extrait le token de la cible d'une redirection vers l'éditeur
     * (/admin/overlay/{outil}/{token}).
     */
    private function redirectToken(string $location): string
    {
        $this->assertSame(1, preg_match('~/([a-z0-9]{16})$~', $location, $matches));

        return $matches[1];
    }
}
