<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfileDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_dashboard_s_affiche_pour_un_joueur_connecte(): void
    {
        // SQLite ne connaît pas FROM_UNIXTIME (utilisé par les requêtes MySQL du projet).
        DB::connection()->getPdo()->sqliteCreateFunction(
            'FROM_UNIXTIME',
            static fn (?int $value): ?string => $value === null ? null : date('Y-m-d H:i:s', $value)
        );

        DB::table('players_info')->insert([
            'steamid' => '[U:1:424242]',
            'name' => 'TestPlayer',
            'display_name' => 'TestPlayer',
            'avatar' => 'https://example.com/avatar.png',
        ]);

        $response = $this->withSession(['steamid' => '76561197960689970'])
            ->get('/profile/dashboard');

        $response->assertOk();
    }

    public function test_le_profil_public_affiche_les_liens_et_infos_renseignees(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction(
            'FROM_UNIXTIME',
            static fn (?int $value): ?string => $value === null ? null : date('Y-m-d H:i:s', $value)
        );

        DB::table('players_info')->insert([
            'steamid' => '[U:1:424242]',
            'name' => 'TestPlayer',
            'display_name' => 'TestPlayer',
            'avatar' => 'https://example.com/avatar.png',
            'twitch_url' => 'https://www.twitch.tv/testplayer',
            'discord_tag' => 'testplayer',
            'birthdate' => '2000-06-15',
            'gear_mouse' => 'Logitech G Pro X Superlight 2',
        ]);

        $response = $this->get('/profile/76561197960689970');

        $response->assertOk();
        $response->assertSee('https://www.twitch.tv/testplayer', false);
        $response->assertSee('<strong>26</strong> ans', false);
        $response->assertSee('Logitech G Pro X Superlight 2');
    }

    public function test_update_links_enregistre_les_liens_valides(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction(
            'FROM_UNIXTIME',
            static fn (?int $value): ?string => $value === null ? null : date('Y-m-d H:i:s', $value)
        );

        DB::table('players_info')->insert([
            'steamid' => '[U:1:424242]',
            'name' => 'TestPlayer',
            'display_name' => 'TestPlayer',
            'avatar' => 'https://example.com/avatar.png',
        ]);

        $response = $this->withSession(['steamid' => '76561197960689970'])
            ->post('/profile/update-links', [
                'twitch_url' => 'https://www.twitch.tv/testplayer',
                'etf2l_url' => 'https://evil.example.com/phishing',
                'discord_tag' => str_repeat('a', 65),
            ]);

        $response->assertRedirect('/profile/dashboard');
        $response->assertSessionHas('error');

        // Rien n'a été enregistré : l'URL hors whitelist et le tag trop long sont refusés.
        $this->assertNull(DB::table('players_info')->where('steamid', '[U:1:424242]')->value('twitch_url'));
    }

    public function test_update_personal_info_enregistre_les_infos_valides(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction(
            'FROM_UNIXTIME',
            static fn (?int $value): ?string => $value === null ? null : date('Y-m-d H:i:s', $value)
        );

        DB::table('players_info')->insert([
            'steamid' => '[U:1:424242]',
            'name' => 'TestPlayer',
            'display_name' => 'TestPlayer',
            'avatar' => 'https://example.com/avatar.png',
        ]);

        $response = $this->withSession(['steamid' => '76561197960689970'])
            ->post('/profile/update-personal-info', [
                'birthdate' => '2000-06-15',
                'gear_keyboard' => 'Wooting 60HE',
                'gear_mouse' => '',
                'gear_monitor' => '<script>x</script>ZOWIE XL2546K',
            ]);

        $response->assertRedirect('/profile/dashboard');
        $response->assertSessionHas('success');

        $player = (array) DB::table('players_info')->where('steamid', '[U:1:424242]')->first();
        $this->assertSame('2000-06-15', (string) $player['birthdate']);
        $this->assertSame('Wooting 60HE', $player['gear_keyboard']);
        $this->assertNull($player['gear_mouse']);
        $this->assertSame('xZOWIE XL2546K', $player['gear_monitor']);
    }

    public function test_update_personal_info_refuse_une_date_invalide(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction(
            'FROM_UNIXTIME',
            static fn (?int $value): ?string => $value === null ? null : date('Y-m-d H:i:s', $value)
        );

        DB::table('players_info')->insert([
            'steamid' => '[U:1:424242]',
            'name' => 'TestPlayer',
            'display_name' => 'TestPlayer',
            'avatar' => 'https://example.com/avatar.png',
        ]);

        $response = $this->withSession(['steamid' => '76561197960689970'])
            ->post('/profile/update-personal-info', ['birthdate' => '2100-01-01']);

        $response->assertRedirect('/profile/dashboard');
        $response->assertSessionHas('error');
        $this->assertNull(DB::table('players_info')->where('steamid', '[U:1:424242]')->value('birthdate'));
    }
}
