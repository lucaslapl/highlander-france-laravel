<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TwitchLive;
use Tests\TestCase;

class TwitchSidebarTest extends TestCase
{
    public function test_endpoint_reponse_sans_cache(): void
    {
        $response = $this->get('/api/twitch-sidebar');

        $response->assertStatus(200);
        $response->assertJson(['data' => []]);
    }

    public function test_endpoint_retourne_la_liste_du_cache(): void
    {
        $cacheFile = hlfr_data_path(TwitchLive::FILE);

        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }

        file_put_contents($cacheFile, (string) json_encode([
            'fetched_at' => time(),
            'channels' => [['login' => 'hlfrcaster', 'viewers' => 5]],
            'sidebar' => [
                ['login' => 'hlfrcaster', 'display_name' => 'Caster HLFR', 'title' => 'Live', 'viewers' => 5, 'hl' => true],
                ['login' => 'autre_fr', 'display_name' => 'Autre FR', 'title' => 'Scrim', 'viewers' => 2, 'hl' => false],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $response = $this->get('/api/twitch-sidebar');

            $response->assertStatus(200);
            $response->assertJson([
                'data' => [
                    ['login' => 'hlfrcaster', 'hl' => true],
                    ['login' => 'autre_fr', 'hl' => false],
                ],
            ]);
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }
}
