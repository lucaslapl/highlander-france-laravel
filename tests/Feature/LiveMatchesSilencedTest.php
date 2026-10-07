<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Fonctionnalité « matchs en direct » au silence par défaut (config
 * hlfr.live_matches.enabled) : l'endpoint /api/live-matches renvoie une
 * liste vide — le badge « MIX EN COURS » de la navigation ne s'affiche
 * jamais — et les pages /live/{server} répondent 404, même si un état
 * live traîne dans le cache (essais manuels, webhooks de test). Le
 * réactivage (HLFR_LIVE_MATCHES=true) restaure la lecture du cache.
 */
class LiveMatchesSilencedTest extends TestCase
{
    public function test_l_endpoint_renvoie_une_liste_vide_badge_masque(): void
    {
        $this->get('/api/live-matches')
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_la_page_live_repond_404(): void
    {
        $this->get('/live/serveur-de-test')->assertNotFound();
    }

    public function test_active_le_badge_remonte_l_etat_live(): void
    {
        // Répertoire de données jetable : on ne touche pas aux artefacts réels.
        $dir = sys_get_temp_dir().'/hlfr-live-test-'.uniqid('', false);
        mkdir($dir, 0777, true);
        config(['hlfr.data_dir' => $dir]);
        config(['hlfr.live_matches.enabled' => true]);

        file_put_contents(hlfr_data_path('live_matches.json'), (string) json_encode([
            'servers' => [
                'serveur-de-test' => [
                    'server' => 'serveur-de-test',
                    'updated_at' => time(),
                    'players' => [],
                ],
            ],
            'last_updated' => [],
        ]));

        $this->get('/api/live-matches')
            ->assertOk()
            ->assertJsonPath('data.0.server', 'serveur-de-test');

        // Fichier jetable : nettoyage.
        @unlink(hlfr_data_path('live_matches.json'));
        @rmdir($dir);
    }
}
