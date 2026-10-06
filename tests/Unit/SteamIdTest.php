<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SteamId;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Conversions entre formats de SteamID (64, 3, 2) via bcmath — utilisées
 * par les webhooks serveurs, le renommage ETF2L et l'overlay OBS.
 */
class SteamIdTest extends TestCase
{
    public function test_convertit_un_steamid64_en_steamid3(): void
    {
        $this->assertSame('[U:1:124652880]', SteamId::toSteamId3('76561198084918608'));
    }

    public function test_convertit_un_steamid3_en_steamid64(): void
    {
        $this->assertSame('76561198084918608', SteamId::toSteamId64('[U:1:124652880]'));
        $this->assertNull(SteamId::toSteamId64('toto'));
    }

    public function test_convertit_un_steamid64_en_steamid2(): void
    {
        $this->assertSame('STEAM_1:0:62326440', SteamId::toSteam2('76561198084918608'));
        $this->assertSame('STEAM_1:1:30067561', SteamId::toSteam2('76561198020400851'));
    }

    #[DataProvider('roundTripProvider')]
    public function test_to_steam2_est_reversible_par_from_steam2(string $steamid64): void
    {
        $this->assertSame($steamid64, SteamId::fromSteam2(SteamId::toSteam2($steamid64)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'compte pair' => ['76561198084918608'],
            'compte impair' => ['76561198020400851'],
        ];
    }
}
