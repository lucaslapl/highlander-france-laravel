<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Tf2EsportsApi;
use Tests\TestCase;

class Tf2EsportsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Supprime le cache JSON éventuellement laissé par un run précédent,
        // pour que chaque test exerce réellement le transport injecté.
        foreach (glob(hlfr_data_path('tf2esports_*.json')) ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * @return array{api: Tf2EsportsApi, urls: array<int, string>}
     */
    private function api(array $responses): array
    {
        $urls = [];
        $transport = static function (string $url) use (&$urls, $responses): array {
            $urls[] = $url;

            return $responses[count($urls) - 1] ?? ['data' => null, 'http_code' => 0, 'curl_error' => '…', 'headers' => []];
        };

        return ['api' => new Tf2EsportsApi($transport), 'urls' => &$urls];
    }

    private function ok(array $data): array
    {
        return ['data' => $data, 'http_code' => 200, 'curl_error' => '', 'headers' => []];
    }

    public function test_team_matches_construit_l_url_a_la_bonne_base(): void
    {
        $r = $this->api([$this->ok(['team_id' => 12628, 'matches' => []])]);

        $result = $r['api']->teamMatches(12628, ['limit' => 2]);

        $this->assertSame(12628, $result['team_id']);
        $this->assertCount(1, $r['urls']);
        $this->assertStringStartsWith('https://tf2esports.com/api/v1/teams/12628/matches', $r['urls'][0]);
        $this->assertStringContainsString('limit=2', $r['urls'][0]);
    }

    public function test_matches_groupe_les_parametres_en_query(): void
    {
        // URL unique pour éviter un éventuel cache partagé : limite 7.
        $r = $this->api([$this->ok(['matches' => []])]);

        $r['api']->matches(['team' => 12628, 'status' => 'Completed', 'limit' => 7]);

        $this->assertStringContainsString('/matches?', $r['urls'][0]);
        $this->assertStringContainsString('team=12628', $r['urls'][0]);
        $this->assertStringContainsString('status=Completed', $r['urls'][0]);
        $this->assertStringContainsString('limit=7', $r['urls'][0]);
    }

    public function test_rankings_revient_sur_reponse_json(): void
    {
        $payload = ['format' => 'Highlander', 'total' => 61, 'rankings' => [['team_id' => '43966']]];
        $r = $this->api([$this->ok($payload)]);

        $result = $r['api']->rankings(['format' => 'Highlander', 'limit' => 3]);

        $this->assertSame('Highlander', $result['format']);
        $this->assertSame([['team_id' => '43966']], $result['rankings']);
    }

    public function test_reponse_http_404_leve_une_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('404');

        $r = $this->api([['data' => ['error' => 'not_found', 'message' => 'Team not found.'], 'http_code' => 404, 'curl_error' => '', 'headers' => []]]);

        $r['api']->teamProfile(999999);
    }

    public function test_erreur_curl_leve_une_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cURL');

        $r = $this->api([['data' => null, 'http_code' => 0, 'curl_error' => 'timeout', 'headers' => []]]);

        $r['api']->playerStats(1);
    }
}
