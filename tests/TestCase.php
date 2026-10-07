<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Amorce le cache ETF2L (table etf2l_api_cache) pour la liste des
     * compétitions interrogée par le remplissage assisté des outils
     * overlay (Etf2lTeamService::competitions(), partagé par Overlay
     * Logs, Overlay Scores, Bracket et Pick/Ban) : les pages admin qui
     * l'affichent restent rapides et sans aucun appel HTTP réel en
     * test. Passer des compétitions pour tester la boîte de
     * remplissage assisté, rien pour le message d'indisponibilité.
     *
     * @param  array<int, array<string, mixed>>  $competitions
     */
    protected function seedEtf2lCompetitionsCache(array $competitions = []): void
    {
        $url = 'https://api-v2.etf2l.org/competition/list?limit=100&since='.date('Y-m-d', time() - 200 * 86400);

        DB::table('etf2l_api_cache')->where('url', $url)->delete();
        DB::table('etf2l_api_cache')->insert([
            'url' => $url,
            'payload' => json_encode(['competitions' => ['data' => $competitions]], JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ]);
    }
}
