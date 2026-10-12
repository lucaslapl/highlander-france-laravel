<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Etf2lMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Matchs ETF2L (API v2) pour l'outil « Overlay Scores » : extraction de
 * l'identifiant depuis un lien etf2l.org/matches/{id}, normalisation d'un
 * match (équipes, compétition et format, maps), titre généré
 * (« Compétition — A vs B »), format de série déduit du nombre de maps,
 * cache par URL dans etf2l_api_cache et cache négatif sur
 * indisponibilité.
 * Fetcher HTTP injecté : aucun réseau réel, seulement la base de test.
 */
class Etf2lMatchServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fetcher factice : réponse par fragment d'URL (null = erreur réseau).
     *
     * @param  array<string, array|null>  $responses
     */
    private function service(array $responses = []): Etf2lMatchService
    {
        $fetcher = static function (string $url) use ($responses): ?array {
            foreach ($responses as $fragment => $payload) {
                if (str_contains($url, $fragment)) {
                    return $payload;
                }
            }

            return null;
        };

        return new Etf2lMatchService($fetcher, 0.0);
    }

    /**
     * Payload d'un match Highlander avec deux équipes connues et maps
     * (forme de l'endpoint /matches/{id}).
     *
     * @return array<string, mixed>
     */
    private function matchPayload(): array
    {
        return [
            'status' => ['code' => 200],
            'match' => [
                'id' => 93233,
                'clan1' => [
                    'id' => 21747,
                    'name' => 'Les Baguettes',
                    'country' => 'France',
                    'steam' => ['avatar' => 'https://example.com/lb.png'],
                ],
                'clan2' => [
                    'id' => 21748,
                    'name' => 'Escouade 6',
                    'country' => 'France',
                    'steam' => ['avatar' => 'https://example.com/e6.png'],
                ],
                'competition' => ['id' => 1060, 'name' => 'Highlander Season 36: High Playoffs', 'type' => 'Highlander'],
                'maps' => ['pl_upward_f10', 'koth_product_final'],
                'r1' => null,
                'r2' => null,
            ],
        ];
    }

    // ─── Lien de match ────────────────────────────────────────────────────

    public function test_un_lien_de_match_donne_son_identifiant(): void
    {
        $this->assertSame(93233, Etf2lMatchService::matchIdFromUrl('https://etf2l.org/matches/93233/'));
        $this->assertSame(93233, Etf2lMatchService::matchIdFromUrl('https://www.etf2l.org/matches/93233'));
        $this->assertSame(93233, Etf2lMatchService::matchIdFromUrl('etf2l.org/matches/93233/'));
    }

    public function test_un_lien_qui_n_est_pas_un_match_est_rejete(): void
    {
        $this->assertNull(Etf2lMatchService::matchIdFromUrl('https://etf2l.org/team/21747'));
        $this->assertNull(Etf2lMatchService::matchIdFromUrl('https://etf2l.org/'));
        $this->assertNull(Etf2lMatchService::matchIdFromUrl(''));
    }

    // ─── Normalisation ────────────────────────────────────────────────────

    public function test_un_match_est_normalise_avec_equipes_et_maps(): void
    {
        $service = $this->service(['/matches/93233' => $this->matchPayload()]);

        $match = $service->match(93233);

        $this->assertNotNull($match);
        $this->assertSame(93233, $match['id']);
        $this->assertSame('https://etf2l.org/matches/93233/', $match['url']);
        $this->assertSame('Highlander Season 36: High Playoffs', $match['competition']['name']);
        $this->assertSame('9v9', $match['category']);
        $this->assertSame(['pl_upward_f10', 'koth_product_final'], $match['maps']);

        $this->assertSame(21747, $match['teams'][0]['id']);
        $this->assertSame('Les Baguettes', $match['teams'][0]['name']);
        $this->assertSame('https://example.com/lb.png', $match['teams'][0]['avatar']);

        $this->assertSame(21748, $match['teams'][1]['id']);
        $this->assertSame('Escouade 6', $match['teams'][1]['name']);
    }

    public function test_le_titre_genere_utilise_la_competition_et_les_noms(): void
    {
        $service = $this->service(['/matches/93233' => $this->matchPayload()]);

        $match = $service->match(93233);

        $this->assertNotNull($match);
        $this->assertSame('Highlander Season 36: High Playoffs — Les Baguettes vs Escouade 6', $match['title']);
    }

    public function test_une_equipe_tbd_restre_inconnue(): void
    {
        // Playoffs : le vainqueur du tour précédent n'est pas encore connu.
        $payload = $this->matchPayload();
        $payload['match']['clan2'] = ['id' => 0, 'name' => 'TBD'];

        $service = $this->service(['/matches/93233' => $payload]);

        $match = $service->match(93233);

        $this->assertNotNull($match);
        $this->assertNotNull($match['teams'][0]);
        $this->assertNull($match['teams'][1]);
        // Le titre garde un repère pour le côté encore inconnu.
        $this->assertSame('Highlander Season 36: High Playoffs — Les Baguettes vs TBD', $match['title']);
    }

    public function test_un_match_6v6_est_categorise_6v6(): void
    {
        $payload = $this->matchPayload();
        $payload['match']['competition']['type'] = '6v6';

        $service = $this->service(['/matches/93233' => $payload]);

        $match = $service->match(93233);

        $this->assertNotNull($match);
        $this->assertSame('6v6', $match['category']);
    }

    public function test_un_match_sans_type_de_competition_n_a_pas_de_categorie(): void
    {
        // Champ absent de l'API : le menu des maps reste sur les deux pools.
        $payload = $this->matchPayload();
        unset($payload['match']['competition']['type']);

        $service = $this->service(['/matches/93233' => $payload]);

        $match = $service->match(93233);

        $this->assertNotNull($match);
        $this->assertNull($match['category']);
        $this->assertSame('Highlander Season 36: High Playoffs — Les Baguettes vs Escouade 6', $match['title']);
    }

    // ─── Format de série détecté du nombre de maps ───────────────────────

    public function test_deux_maps_donnent_le_format_classique(): void
    {
        $format = Etf2lMatchService::deriveFormat(2);

        $this->assertNotNull($format);
        $this->assertSame('fixed', $format['format']);
        $this->assertSame(0, $format['wins_needed']);
    }

    public function test_trois_maps_donnent_un_bo3(): void
    {
        $format = Etf2lMatchService::deriveFormat(3);

        $this->assertNotNull($format);
        $this->assertSame('bo3', $format['format']);
        $this->assertSame(2, $format['wins_needed']);
    }

    public function test_cinq_maps_donnent_un_bo5(): void
    {
        $format = Etf2lMatchService::deriveFormat(5);

        $this->assertNotNull($format);
        $this->assertSame('bo5', $format['format']);
        $this->assertSame(3, $format['wins_needed']);
    }

    public function test_un_autre_compte_de_maps_ne_donne_pas_de_format(): void
    {
        // Maps partiellement révélées (pick / ban en cours), cas exotique :
        // le choix du format reste manuel dans l'outil.
        $this->assertNull(Etf2lMatchService::deriveFormat(0));
        $this->assertNull(Etf2lMatchService::deriveFormat(1));
        $this->assertNull(Etf2lMatchService::deriveFormat(4));
    }

    // ─── Indisponibilités et cache ────────────────────────────────────────

    public function test_une_api_indisponible_renvoie_null(): void
    {
        $service = $this->service(['/matches/93233' => null]);

        $this->assertNull($service->match(93233));
    }

    public function test_un_match_inconnu_renvoie_null(): void
    {
        $service = $this->service(['/matches/93233' => ['status' => ['code' => 404]]]);

        $this->assertNull($service->match(93233));
    }

    public function test_un_identifiant_invalide_est_rejete_sans_appel(): void
    {
        $this->assertNull($this->service()->match(0));
    }
}
