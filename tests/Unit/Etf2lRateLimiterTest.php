<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Etf2lRateLimiter;
use Tests\TestCase;

/**
 * Espacement global des appels à l'API ETF2L : le dernier appel est
 * mémorisé dans un fichier protégé par flock, partagé entre tous les
 * processus du site (le délai par défaut est neutralisé en tests —
 * seules les variantes à délai explicite sont chronométrées ici).
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
}
