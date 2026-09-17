<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TwitchLive;
use Tests\TestCase;

class TwitchLiveTest extends TestCase
{
    public function test_merge_simulated_conserve_les_entrees_simulees(): void
    {
        $previous = [
            ['login' => 'highlanderfrance', 'simulated' => true, 'title' => 'Simulé A'],
            ['login' => 'autre_chaine', 'simulated' => true, 'title' => 'Simulé B'],
            ['login' => 'highlanderfrance', 'title' => 'Réel obsolète (écrasé par le refresh)'],
        ];

        $live = [
            ['login' => 'highlanderfrance', 'title' => 'Réel en direct', 'viewers' => 12],
        ];

        $merged = TwitchLive::mergeSimulated($previous, $live);

        // Les réelles priment sur les simulées du même login, les autres
        // simulées survivent au refresh (elles restent jusqu'au reset admin).
        $this->assertSame(
            ['highlanderfrance', 'autre_chaine'],
            array_map(static fn (array $ch): string => (string) $ch['login'], $merged),
        );
        $this->assertSame('Réel en direct', $merged[0]['title']);
        $this->assertSame('Simulé B', $merged[1]['title']);
    }

    public function test_merge_simulated_sans_entree_simulee(): void
    {
        $previous = [
            ['login' => 'highlanderfrance', 'title' => 'Réel obsolète'],
        ];
        $live = [
            ['login' => 'highlanderfrance', 'title' => 'Réel en direct'],
        ];

        $this->assertSame($live, TwitchLive::mergeSimulated($previous, $live));
    }

    public function test_merge_simulated_sans_entree_reelle(): void
    {
        $previous = [
            ['login' => 'highlanderfrance', 'simulated' => true, 'title' => 'Simulé A'],
        ];

        $this->assertSame($previous, TwitchLive::mergeSimulated($previous, []));
    }
}
