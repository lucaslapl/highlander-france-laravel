<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Etf2lTeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Équipes ETF2L (API v2) pour le remplissage assisté des outils overlay :
 * liste des compétitions Highlander récentes, équipes d'une compétition
 * (nom, avatar, pays, identifiant pour le roster) avec pagination,
 * dédoublonnage et tri alphabétique, roster d'une équipe (SteamID64 de
 * ses joueurs), cache par URL dans etf2l_api_cache. Fetcher HTTP
 * injecté : aucun réseau réel, seulement la base de test.
 */
class Etf2lTeamServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fetcher factice : réponse par fragment d'URL, compteur d'appels.
     *
     * @param  array<string, array|null>  $responses  fragment d'URL => payload (null = erreur réseau)
     * @param  int  &$calls  compteur d'appels
     */
    private function service(array $responses, ?int &$calls = null): Etf2lTeamService
    {
        $calls = 0;
        $counter = &$calls;

        $fetcher = static function (string $url) use ($responses, &$counter): ?array {
            $counter++;

            foreach ($responses as $fragment => $payload) {
                if (str_contains($url, $fragment)) {
                    return $payload;
                }
            }

            return null;
        };

        return new Etf2lTeamService($fetcher, 0.0);
    }

    private function competitionsPayload(): array
    {
        return [
            'competitions' => ['data' => [
                ['id' => 1060, 'type' => 'Highlander', 'name' => 'Crit or Treat! Highlander One-Night-Cup', 'archived' => false],
                ['id' => 1053, 'type' => '6v6', 'name' => '6v6 Season 53', 'archived' => false],
                ['id' => 1050, 'type' => 'Highlander', 'name' => '  Highlander Season 36: High ', 'archived' => false],
                ['id' => 975, 'type' => 'Highlander', 'name' => 'Highlander Season 34: High Playoffs', 'archived' => true],
            ]],
            'status' => ['code' => 200],
        ];
    }

    /**
     * @param  array<int, array{name: string, avatar: ?string, country: ?string}>  $teams
     */
    private function teamsPayload(array $teams, int $lastPage = 1): array
    {
        return [
            'teams' => [
                'current_page' => 1,
                'last_page' => $lastPage,
                'data' => array_map(static fn (array $t): array => [
                    'id' => $t['name'] !== '' ? crc32($t['name']) : 0,
                    'name' => $t['name'],
                    'country' => $t['country'] ?? '',
                    'dropped' => 0,
                    'steam' => ['avatar' => $t['avatar']],
                ], $teams),
            ],
            'status' => ['code' => 200],
        ];
    }

    public function test_les_competitions_highlander_recentes_sont_listees(): void
    {
        $service = $this->service(['competition/list' => $this->competitionsPayload()]);

        $competitions = $service->competitions();

        $this->assertSame([
            ['id' => 1060, 'name' => 'Crit or Treat! Highlander One-Night-Cup', 'archived' => false],
            ['id' => 1050, 'name' => 'Highlander Season 36: High', 'archived' => false],
            ['id' => 975, 'name' => 'Highlander Season 34: High Playoffs', 'archived' => true],
        ], $competitions);
    }

    public function test_les_competitions_6v6_sont_listees_avec_le_filtre_de_type(): void
    {
        $service = $this->service(['competition/list' => $this->competitionsPayload()]);

        $competitions = $service->competitions('6v6');

        // Seule la compétition 6v6 passe le filtre de type : les outils
        // Highlander existants ne la voient pas (comportement inchangé).
        $this->assertSame([
            ['id' => 1053, 'name' => '6v6 Season 53', 'archived' => false],
        ], $competitions);
    }

    public function test_les_equipes_sont_normalisees_triees_et_dedoublonnees(): void
    {
        $page1 = $this->teamsPayload([
            ['name' => 'DD14', 'avatar' => 'https://etf2l.org/dd14.png', 'country' => 'France'],
            ['name' => '  AKATSUKI  ', 'avatar' => 'javascript:alert(1)', 'country' => 'DominicanRepublic'],
            ['name' => 'ЭТО МОЁ БОЛОТО', 'avatar' => null, 'country' => 'Russia'],
        ]);
        // Même équipe que la page 1 (même id, crc32 du nom) : ignorée.
        $page1['teams']['data'][3] = ['id' => crc32('DD14'), 'name' => 'DD14', 'country' => 'France', 'dropped' => 0, 'steam' => ['avatar' => 'https://etf2l.org/dd14.png']];

        $service = $this->service(['teams?limit=100&page=1' => $page1]);

        $teams = $service->teams(1050);

        $this->assertSame('AKATSUKI', $teams[0]['name']);
        $this->assertSame('', $teams[0]['avatar']);
        $this->assertSame('DominicanRepublic', $teams[0]['country']);
        $this->assertSame('DD14', $teams[1]['name']);
        $this->assertSame('https://etf2l.org/dd14.png', $teams[1]['avatar']);
        $this->assertSame('ЭТО МОЁ БОЛОТО', $teams[2]['name']);
        $this->assertCount(3, $teams);

        // L'identifiant ETF2L voyage avec l'équipe : le remplissage assisté
        // s'en sert pour récupérer son roster (voir ci-dessous).
        $this->assertSame((string) crc32('DD14'), (string) $teams[1]['id']);
    }

    public function test_la_pagination_est_suivie_jusqu_a_la_derniere_page(): void
    {
        $page1 = $this->teamsPayload([
            ['name' => 'Alpha', 'avatar' => null, 'country' => ''],
            ['name' => 'Bravo', 'avatar' => null, 'country' => ''],
        ], 2);
        $page2 = $this->teamsPayload([['name' => 'Charlie', 'avatar' => null, 'country' => '']], 2);

        $service = $this->service([
            'teams?limit=100&page=1' => $page1,
            'teams?limit=100&page=2' => $page2,
        ], $calls);

        $teams = $service->teams(1050);

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($teams, 'name'));
        $this->assertSame(2, $calls);
    }

    public function test_la_lecture_s_arrete_au_garde_fou_de_300_equipes(): void
    {
        // 2 équipes uniques par page, l'API annonce 500 pages : la lecture
        // s'arrête au garde-fou de 300 équipes (150 pages consultées).
        $calls = 0;
        $fetcher = static function (string $url) use (&$calls): ?array {
            $calls++;
            if (! preg_match('~page=(\d+)~', $url, $m)) {
                return null;
            }
            $page = (int) $m[1];

            return [
                'teams' => [
                    'last_page' => 500,
                    'data' => [
                        ['id' => $page * 2 - 1, 'name' => 'Team '.($page * 2 - 1), 'country' => '', 'dropped' => 0, 'steam' => ['avatar' => null]],
                        ['id' => $page * 2, 'name' => 'Team '.($page * 2), 'country' => '', 'dropped' => 0, 'steam' => ['avatar' => null]],
                    ],
                ],
                'status' => ['code' => 200],
            ];
        };

        $service = new Etf2lTeamService($fetcher, 0.0);

        $teams = $service->teams(1050);

        $this->assertCount(300, $teams);
        $this->assertSame(150, $calls);
    }

    public function test_une_api_indisponible_renvoie_une_liste_vide(): void
    {
        $service = $this->service(['teams' => null]);

        $this->assertSame([], $service->teams(1050));
    }

    public function test_les_reponses_sont_mises_en_cache_par_url(): void
    {
        $page1 = $this->teamsPayload([['name' => 'DD14', 'avatar' => 'https://etf2l.org/a.png', 'country' => 'France']]);

        $service = $this->service(['teams?limit=100&page=1' => $page1], $calls);

        $this->assertCount(1, $service->teams(1050));
        $this->assertCount(1, $service->teams(1050));
        $this->assertSame(1, $calls);
    }

    public function test_un_payload_throttle_429_est_rejete_et_mis_en_cache_negativement(): void
    {
        // Corps renvoyé par le throttle Laravel de l'API ( Too Many Attempts ).
        $throttle = ['status' => ['code' => 429, 'message' => 'Too Many Attempts.']];
        $service = $this->service(['teams?limit=100&page=1' => $throttle], $calls);

        // Le payload 429 n'est jamais servi : liste vide.
        $this->assertSame([], $service->teams(1050));
        $this->assertSame(1, $calls);

        // Cache négatif écrit pour l'URL : l'API n'est pas martelée ensuite.
        $this->assertSame([], $service->teams(1050));
        $this->assertSame(1, $calls);

        $row = DB::table('etf2l_api_cache')
            ->where('url', 'https://api-v2.etf2l.org/competition/1050/teams?limit=100&page=1')
            ->first();

        $this->assertNotNull($row);
        $payload = json_decode((string) $row->payload, true);
        // Marqueur négatif (clé privée NEGATIVE_ERROR du service).
        $this->assertSame('hlfr_etf2l_indisponible', $payload['error'] ?? null);
    }

    public function test_le_cache_negatif_expire_relance_l_api(): void
    {
        // Entrée négative périmée (TTL 120 s dépassé) : l'API est rappelée
        // et le payload valide écrase le marqueur négatif.
        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api-v2.etf2l.org/competition/1050/teams?limit=100&page=1',
            'payload' => json_encode(['error' => 'hlfr_etf2l_indisponible'], JSON_THROW_ON_ERROR),
            'fetched_at' => time() - 121,
        ]);

        $page1 = $this->teamsPayload([['name' => 'DD14', 'avatar' => null, 'country' => '']]);
        $service = $this->service(['teams?limit=100&page=1' => $page1], $calls);

        $this->assertSame(['DD14'], array_column($service->teams(1050), 'name'));
        $this->assertSame(1, $calls);

        $row = DB::table('etf2l_api_cache')
            ->where('url', 'https://api-v2.etf2l.org/competition/1050/teams?limit=100&page=1')
            ->first();

        $payload = json_decode((string) $row->payload, true);
        $this->assertSame(200, $payload['status']['code'] ?? null);
    }

    // ─── Roster d'une équipe (endpoint /team/{id}) ────────────────────────

    /**
     * Payload /team/{id} au format de l'API v2, avec un bloc « steam »
     * complet (id64, id2 STEAM_1, id3 [U:1:N]) comme dans la vraie
     * réponse.
     *
     * @return array<string, mixed>
     */
    private function teamPayload(): array
    {
        return [
            'status' => ['code' => 200],
            'team' => [
                'id' => 21747,
                'name' => '  The Piece of Pie ',
                'steam' => ['avatar' => 'https://etf2l.org/pie.jpg'],
                'players' => [
                    ['id' => 92114, 'name' => 'xine', 'steam' => [
                        'id64' => '76561198000552896', 'id' => 'STEAM_1:0:20143584', 'id3' => '[U:1:40287168]',
                    ]],
                    // Pas de id64 : id2 (STEAM_1:X:Y) converti en SteamID64.
                    ['id' => 72958, 'name' => 'dexton123', 'steam' => ['id' => 'STEAM_1:0:12345']],
                    // Doublon du premier joueur via id3 seul : dédupliqué.
                    ['id' => 72959, 'name' => 'xine alias', 'steam' => ['id3' => '[U:1:40287168]']],
                    // Aucun SteamID exploitable : ignoré.
                    ['id' => 72960, 'name' => 'ghost', 'steam' => []],
                    // Entrée non-tableau : ignorée sans erreur.
                    'corrompu',
                ],
            ],
        ];
    }

    public function test_le_roster_est_normalise_et_dedupliqu(): void
    {
        $service = $this->service(['/team/21747' => $this->teamPayload()], $calls);

        $roster = $service->roster(21747);

        $this->assertSame(21747, $roster['id']);
        $this->assertSame('The Piece of Pie', $roster['name']);
        // xine (id64), dexton123 (id2 converti), le doublon id3 est
        // dédupliqué et l'entrée sans SteamID ignorée.
        $this->assertSame(['76561198000552896', '76561197960290418'], $roster['players']);
        // Pseudos officiels par SteamID64 pour l'outil Overlay Rosters :
        // le doublon dédupliqué garde le pseudo du joueur d'origine.
        $this->assertSame([
            '76561198000552896' => 'xine',
            '76561197960290418' => 'dexton123',
        ], $roster['names']);
        $this->assertSame(1, $calls);
    }

    public function test_le_roster_est_mis_en_cache_par_url(): void
    {
        $service = $this->service(['/team/21747' => $this->teamPayload()], $calls);

        $service->roster(21747);
        $service->roster(21747);

        $this->assertSame(1, $calls);
    }

    public function test_un_roster_introuvable_ou_vide_renvoie_null(): void
    {
        // API indisponible pour cette équipe.
        $service = $this->service(['/team/1' => null]);
        $this->assertNull($service->roster(1));

        // Payload sans bloc « team ».
        $service = $this->service(['/team/2' => ['status' => ['code' => 200]]]);
        $this->assertNull($service->roster(2));

        // Roster vide : pas de référence d'identité exploitable.
        $empty = $this->teamPayload();
        $empty['team']['players'] = [];
        $service = $this->service(['/team/3' => $empty]);
        $this->assertNull($service->roster(3));
    }

    public function test_un_identifiant_d_equipe_invalide_renvoie_null(): void
    {
        $service = $this->service([], $calls);

        $this->assertNull($service->roster(0));
        $this->assertNull($service->roster(-5));
        $this->assertSame(0, $calls);
    }
}
