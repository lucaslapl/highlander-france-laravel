<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Etf2lNameResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Endpoint POST /api/server/etf2l-names : résolution des pseudos ETF2L des
 * joueurs en jeu pour le plugin SourceMod hlfr_etf2l_rename (token partagé
 * + IP allowlist, même modèle que match-ended / live-status).
 */
class ServerEtf2lNamesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-test-webhook';

    private const SID2_PSYCHO = 'STEAM_1:0:62326440';

    private const SID64_PSYCHO = '76561198084918608';

    private const SID2_AUTRE = 'STEAM_1:1:30067561';

    protected function setUp(): void
    {
        parent::setUp();

        config(['hlfr.server_webhook_token' => self::TOKEN]);
        config(['hlfr.server_webhook_allowed_ips' => '']);
    }

    /**
     * Résolveur réel branché sur un fetcher factice (aucun réseau) :
     * les réponses sont servies par la base de test (cache API).
     *
     * @param  array<string, array|null>  $responses  sous-chaîne d'URL => payload (null = erreur réseau)
     */
    private function resolver(array $responses = []): Etf2lNameResolver
    {
        $fetcher = static function (string $url) use ($responses): ?array {
            foreach ($responses as $needle => $payload) {
                // Les clés numériques de $responses sont des int en PHP.
                if (str_contains($url, (string) $needle)) {
                    return $payload;
                }
            }

            return null;
        };

        $resolver = new Etf2lNameResolver($fetcher);
        $this->app->instance(Etf2lNameResolver::class, $resolver);

        return $resolver;
    }

    public function test_refuse_les_requetes_sans_token_valide(): void
    {
        $this->resolver();

        $response = $this->postJson('/api/server/etf2l-names', [
            'token' => 'mauvais-token',
            'server' => 'comp01',
            'players' => [self::SID2_PSYCHO],
        ]);

        $response->assertStatus(403);
    }

    public function test_refuse_les_ip_non_autorisees(): void
    {
        config(['hlfr.server_webhook_allowed_ips' => '1.2.3.4']);

        $response = $this->postJson('/api/server/etf2l-names', [
            'token' => self::TOKEN,
            'server' => 'comp01',
            'players' => [self::SID2_PSYCHO],
        ]);

        $response->assertStatus(403);
    }

    public function test_refuse_une_liste_de_joueurs_invalide(): void
    {
        $this->resolver();

        $cases = [
            'players absent' => ['token' => self::TOKEN, 'server' => 'comp01'],
            'players vide' => ['token' => self::TOKEN, 'server' => 'comp01', 'players' => []],
            'steamid invalide' => ['token' => self::TOKEN, 'server' => 'comp01', 'players' => ['toto']],
            'steamid non string' => ['token' => self::TOKEN, 'server' => 'comp01', 'players' => [12345]],
            'trop de joueurs' => [
                'token' => self::TOKEN,
                'server' => 'comp01',
                'players' => array_fill(0, 33, self::SID2_PSYCHO),
            ],
        ];

        foreach ($cases as $label => $body) {
            $this->postJson('/api/server/etf2l-names', $body)
                ->assertStatus(400, "Cas « $label » devrait renvoyer 400.");
        }
    }

    public function test_renvoie_les_pseudos_resolus_indexes_par_steamid(): void
    {
        $this->resolver();

        // Joueur connu via le cache API persistant ; l'autre reste non résolu.
        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api-v2.etf2l.org/player/'.self::SID64_PSYCHO,
            'payload' => json_encode([
                'player' => ['id' => 112835, 'name' => 'Psycho'],
                'status' => ['code' => 200, 'message' => 'OK'],
            ], JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ]);

        $response = $this->postJson('/api/server/etf2l-names', [
            'token' => self::TOKEN,
            'server' => 'comp01',
            'players' => [self::SID2_PSYCHO, self::SID2_AUTRE],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'players' => [
                    self::SID2_PSYCHO => ['name' => 'Psycho', 'etf2l_id' => 112835],
                ],
            ]);
        // Le joueur non résolu est simplement absent de la réponse.
        $response->assertJsonMissingPath('players.'.self::SID2_AUTRE);
    }

    public function test_appelle_le_fetcher_pour_les_joueurs_inconnus_du_cache(): void
    {
        $this->resolver([
            self::SID64_PSYCHO => [
                'player' => ['id' => 112835, 'name' => 'Psycho'],
                'status' => ['code' => 200, 'message' => 'OK'],
            ],
        ]);

        $response = $this->postJson('/api/server/etf2l-names', [
            'token' => self::TOKEN,
            'server' => 'comp01',
            'players' => [self::SID2_PSYCHO],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'players' => [
                    self::SID2_PSYCHO => ['name' => 'Psycho', 'etf2l_id' => 112835],
                ],
            ]);

        // Le payload API a bien été mis en cache pour les appels suivants.
        $this->assertDatabaseHas('etf2l_api_cache', [
            'url' => 'https://api-v2.etf2l.org/player/'.self::SID64_PSYCHO,
        ]);
    }
}
