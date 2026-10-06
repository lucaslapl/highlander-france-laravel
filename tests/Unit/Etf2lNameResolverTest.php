<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Etf2lNameResolver;
use App\Services\SteamId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Résolution des pseudos ETF2L par SteamID (endpoint /api/server/etf2l-names,
 * consommé par le plugin hlfr_etf2l_rename). Fetcher HTTP injecté : aucun
 * réseau réel, seulement la base de test.
 */
class Etf2lNameResolverTest extends TestCase
{
    use RefreshDatabase;

    private const SID2_PSYCHO = 'STEAM_1:0:62326440';

    private const SID64_PSYCHO = '76561198084918608';

    private const SID2_AUTRE = 'STEAM_1:1:30067561';

    private const SID64_AUTRE = '76561198020400851';

    /**
     * Fetcher factice : réponse par SteamID64, compteur d'appels pour
     * vérifier qu'aucun appel HTTP n'est émis quand le cache suffit.
     *
     * @param  array<string, array|null>  $responses  steamid64 => payload (null = erreur réseau)
     * @param  int  &$calls  compteur d'appels
     */
    private function resolver(array $responses, ?int &$calls = null): Etf2lNameResolver
    {
        $calls = 0;
        $counter = &$calls;

        $fetcher = static function (string $url) use ($responses, &$counter): ?array {
            $counter++;

            foreach ($responses as $steamid64 => $payload) {
                // Les clés numériques de $responses sont des int en PHP.
                if (str_contains($url, (string) $steamid64)) {
                    return $payload;
                }
            }

            return null;
        };

        return new Etf2lNameResolver($fetcher);
    }

    private function playerPayload(string $name, int $id): array
    {
        return [
            'player' => ['id' => $id, 'name' => $name, 'country' => 'France'],
            'status' => ['code' => 200, 'message' => 'OK'],
        ];
    }

    private function notFoundPayload(): array
    {
        return [
            'status' => ['code' => 404, 'message' => 'No player was found'],
        ];
    }

    private function seedApiCache(string $steamid64, array $payload, int $ageSeconds = 0): void
    {
        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api-v2.etf2l.org/player/'.$steamid64,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'fetched_at' => time() - $ageSeconds,
        ]);
    }

    // ─── Niveau 1 : table etf2l_players ──────────────────────────────────

    public function test_resoud_via_la_table_etf2l_players_sans_appel_http(): void
    {
        DB::table('etf2l_players')->insert([
            'team_id' => 15176,
            'player_id' => 112835,
            'name' => 'Psycho',
            'steamid64' => self::SID64_PSYCHO,
        ]);

        $resolved = $this->resolver([])->resolve([self::SID2_PSYCHO]);

        $this->assertSame(['name' => 'Psycho', 'etf2l_id' => 112835], $resolved[self::SID2_PSYCHO]);
    }

    // ─── Niveau 2 : cache API persistant ─────────────────────────────────

    public function test_resoud_via_le_cache_api_sans_appel_http(): void
    {
        $this->seedApiCache(self::SID64_PSYCHO, $this->playerPayload('Psycho', 112835));

        $resolved = $this->resolver([])->resolve([self::SID2_PSYCHO]);

        $this->assertSame(['name' => 'Psycho', 'etf2l_id' => 112835], $resolved[self::SID2_PSYCHO]);
    }

    public function test_le_cache_api_perime_est_ignore(): void
    {
        $this->seedApiCache(self::SID64_PSYCHO, $this->playerPayload('Psycho', 112835), 25 * 3600);

        $calls = 0;
        $resolved = $this->resolver([self::SID64_PSYCHO => $this->playerPayload('Psycho', 112835)], $calls)
            ->resolve([self::SID2_PSYCHO]);

        $this->assertSame(1, $calls);
        $this->assertSame(['name' => 'Psycho', 'etf2l_id' => 112835], $resolved[self::SID2_PSYCHO]);
    }

    // ─── Niveau 3 : API ETF2L + écriture du cache ────────────────────────

    public function test_appelle_l_api_pour_un_joueur_inconnu_et_ecrit_le_cache(): void
    {
        $calls = 0;
        $resolver = $this->resolver([self::SID64_PSYCHO => $this->playerPayload('Psycho', 112835)], $calls);

        $resolved = $resolver->resolve([self::SID2_PSYCHO]);

        $this->assertSame(1, $calls);
        $this->assertSame(['name' => 'Psycho', 'etf2l_id' => 112835], $resolved[self::SID2_PSYCHO]);

        // Le payload est mis en cache : un second appel ne rappelle pas l'API.
        $this->assertDatabaseHas('etf2l_api_cache', [
            'url' => 'https://api-v2.etf2l.org/player/'.self::SID64_PSYCHO,
            'fetched_at' => time(),
        ]);

        $resolved2 = $resolver->resolve([self::SID2_PSYCHO]);
        $this->assertSame(1, $calls);
        $this->assertSame(['name' => 'Psycho', 'etf2l_id' => 112835], $resolved2[self::SID2_PSYCHO]);
    }

    public function test_cache_negatif_404_sans_nouvel_appel_api(): void
    {
        $calls = 0;
        $resolver = $this->resolver([self::SID64_PSYCHO => $this->notFoundPayload()], $calls);

        $this->assertSame([], $resolver->resolve([self::SID2_PSYCHO]));
        $this->assertSame(1, $calls);

        // Deuxième résolution : le cache négatif (12 h) suffit.
        $this->assertSame([], $resolver->resolve([self::SID2_PSYCHO]));
        $this->assertSame(1, $calls);
    }

    public function test_cache_negatif_perime_reappelle_l_api(): void
    {
        $this->seedApiCache(self::SID64_PSYCHO, $this->notFoundPayload(), 13 * 3600);

        $calls = 0;
        $resolver = $this->resolver([self::SID64_PSYCHO => $this->playerPayload('NouveauNom', 112835)], $calls);

        $resolved = $resolver->resolve([self::SID2_PSYCHO]);

        $this->assertSame(1, $calls);
        $this->assertSame(['name' => 'NouveauNom', 'etf2l_id' => 112835], $resolved[self::SID2_PSYCHO]);
    }

    public function test_erreur_reseau_sans_cache_rejoue_l_appel_suivant(): void
    {
        $calls = 0;
        $resolver = $this->resolver([self::SID64_PSYCHO => null], $calls);

        $this->assertSame([], $resolver->resolve([self::SID2_PSYCHO]));
        $this->assertSame([], $resolver->resolve([self::SID2_PSYCHO]));
        $this->assertSame(2, $calls);
        $this->assertDatabaseMissing('etf2l_api_cache', [
            'url' => 'https://api-v2.etf2l.org/player/'.self::SID64_PSYCHO,
        ]);
    }

    // ─── Cas divers ──────────────────────────────────────────────────────

    public function test_plusieurs_joueurs_avec_sources_mixtes(): void
    {
        // A : connu via etf2l_players ; B : connu via l'API (cache écrit).
        DB::table('etf2l_players')->insert([
            'team_id' => 15176,
            'player_id' => 999,
            'name' => 'ViaTable',
            'steamid64' => self::SID64_PSYCHO,
        ]);

        $calls = 0;
        $resolver = $this->resolver([self::SID64_AUTRE => $this->playerPayload('ViaApi', 42)], $calls);

        $resolved = $resolver->resolve([self::SID2_PSYCHO, self::SID2_AUTRE, 'steamid-pourri']);

        $this->assertSame(1, $calls);
        $this->assertSame(['name' => 'ViaTable', 'etf2l_id' => 999], $resolved[self::SID2_PSYCHO]);
        $this->assertSame(['name' => 'ViaApi', 'etf2l_id' => 42], $resolved[self::SID2_AUTRE]);
        $this->assertArrayNotHasKey('steamid-pourri', $resolved);
    }

    public function test_les_pseudos_sont_assainis(): void
    {
        // Caractères de contrôle, espaces multiples, 40 octets (> limite 32).
        $brut = "  Psycho\t\nX   ".str_repeat('é', 20);
        $resolved = $this->resolver([self::SID64_PSYCHO => $this->playerPayload($brut, 112835)])
            ->resolve([self::SID2_PSYCHO]);

        $name = $resolved[self::SID2_PSYCHO]['name'];
        // \t et \n sont supprimés (caractères de contrôle), les espaces
        // multiples compressés, le tout plafonné à 32 octets.
        $this->assertSame('PsychoX '.str_repeat('é', 12), $name);
        $this->assertLessThanOrEqual(32, strlen($name));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $name);
    }

    public function test_entrée_invalide_ne_leve_pas_derreur(): void
    {
        $calls = 0;
        $resolved = $this->resolver([], $calls)->resolve([null, 42, ['STEAM_1:0:1'], 'toto', '']);

        $this->assertSame([], $resolved);
        $this->assertSame(0, $calls);
    }

    public function test_les_alias_steamid2_pointent_vers_le_meme_pseudo(): void
    {
        // STEAM_0:0:x et STEAM_1:0:x désignent le même compte.
        $resolved = $this->resolver([self::SID64_PSYCHO => $this->playerPayload('Psycho', 112835)])
            ->resolve(['STEAM_0:0:62326440', self::SID2_PSYCHO]);

        $this->assertSame(['name' => 'Psycho', 'etf2l_id' => 112835], $resolved['STEAM_0:0:62326440']);
        $this->assertSame(['name' => 'Psycho', 'etf2l_id' => 112835], $resolved[self::SID2_PSYCHO]);
    }

    // ─── Variantes SteamID3 (logs.tf → overlay OBS) ────────────────────────

    public function test_resolve_by_steamid3_indexe_par_le_steamid3_origine(): void
    {
        $steamid3 = SteamId::toSteamId3(self::SID64_PSYCHO);

        $resolved = $this->resolver([self::SID64_PSYCHO => $this->playerPayload('Psycho', 112835)])
            ->resolveBySteamId3([$steamid3]);

        $this->assertSame(['name' => 'Psycho', 'etf2l_id' => 112835], $resolved[$steamid3]);
    }

    public function test_resolve_by_steamid3_ignore_les_entrees_invalides(): void
    {
        $steamid3 = SteamId::toSteamId3(self::SID64_AUTRE);

        $resolved = $this->resolver([self::SID64_AUTRE => $this->playerPayload('ViaApi', 42)])
            ->resolveBySteamId3([$steamid3, 'toto', '[X:2:1]', '']);

        $this->assertSame(['name' => 'ViaApi', 'etf2l_id' => 42], $resolved[$steamid3]);
        $this->assertSame([$steamid3], array_keys($resolved));
    }
}
