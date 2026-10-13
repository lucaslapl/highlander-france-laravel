<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Etf2lRateLimiter;
use Tests\TestCase;

/**
 * Espacement global des appels à l'API ETF2L : le dernier appel et le
 * budget annoncé (x-ratelimit-*) sont mémorisés dans un fichier protégé
 * par flock, partagé entre tous les processus du site (le délai par
 * défaut est neutralisé en tests — seules les variantes à délai
 * explicite sont chronométrées ici).
 */
class Etf2lRateLimiterTest extends TestCase
{
    public function test_un_delai_explicite_espace_deux_appels_consecutifs(): void
    {
        $limiter = new Etf2lRateLimiter(0.05);

        $start = microtime(true);
        $limiter->wait();
        $limiter->wait();
        $elapsed = microtime(true) - $start;

        // Le deuxième appel attend le délai écoulé depuis le premier.
        $this->assertGreaterThanOrEqual(0.04, $elapsed);

        // L'horodatage du dernier appel vit dans le répertoire de données.
        $this->assertFileExists(hlfr_data_path('etf2l_rate_limit.lock'));
    }

    public function test_un_delai_nul_ne_bloque_pas(): void
    {
        $limiter = new Etf2lRateLimiter(0.0);

        $start = microtime(true);
        $limiter->wait();
        $limiter->wait();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.04, $elapsed);
    }

    public function test_les_en_tetes_remontent_le_budget_dans_l_etat_partage(): void
    {
        $limiter = new Etf2lRateLimiter(0.0);
        $limiter->wait();

        $limiter->reportFromHeaders([
            'x-ratelimit-remaining' => '55',
            'x-ratelimit-limit' => '60',
        ]);

        $state = json_decode((string) file_get_contents(hlfr_data_path('etf2l_rate_limit.lock')), true);

        $this->assertIsArray($state);
        $this->assertSame(55, $state['remaining']);
        $this->assertSame(60, $state['limit']);
        $this->assertGreaterThan(0.0, (float) $state['budget_at']);
    }

    public function test_un_budget_critique_declenche_une_pause_adaptive(): void
    {
        $limiter = new Etf2lRateLimiter(0.05);

        // Budget quasi épuisé (≤ 3 requêtes restantes) : pause longue.
        $limiter->reportFromHeaders([
            'x-ratelimit-remaining' => '2',
            'x-ratelimit-limit' => '60',
        ]);

        $limiter->wait();
        $start = microtime(true);
        $limiter->wait();
        $elapsed = microtime(true) - $start;

        // 0,05 s d'espacement + 15 × 0,05 s de pause critique.
        $this->assertGreaterThanOrEqual(0.7, $elapsed);
    }

    public function test_un_budget_perime_ne_declenche_pas_de_pause(): void
    {
        // Budget annoncé il y a plus d'une minute : la fenêtre s'est
        // rechargée depuis, l'espacement de base suffit.
        file_put_contents(hlfr_data_path('etf2l_rate_limit.lock'), (string) json_encode([
            'last' => microtime(true),
            'remaining' => 1,
            'limit' => 60,
            'budget_at' => microtime(true) - 200,
        ]));

        $limiter = new Etf2lRateLimiter(0.05);
        $limiter->wait();
        $start = microtime(true);
        $limiter->wait();
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.04, $elapsed);
        $this->assertLessThan(0.4, $elapsed);
    }

    public function test_l_ancien_format_d_horodatage_reste_lisible(): void
    {
        // État écrit par l'ancienne version du limiteur (horodatage brut).
        file_put_contents(hlfr_data_path('etf2l_rate_limit.lock'), (string) (microtime(true) - 10));

        $limiter = new Etf2lRateLimiter(0.05);
        $limiter->wait();
        $limiter->wait();

        // Le nouvel état est réécrit au format JSON avec budget inconnu.
        $state = json_decode((string) file_get_contents(hlfr_data_path('etf2l_rate_limit.lock')), true);
        $this->assertIsArray($state);
        $this->assertGreaterThan(0.0, (float) $state['last']);
        $this->assertArrayNotHasKey('remaining', $state);
    }
}
