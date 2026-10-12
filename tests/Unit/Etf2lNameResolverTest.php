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
 * consommé par le plugin hlfr_etf2l_rename) et des équipes candidates d'un
 * log (vote de noms automatiques de l'Overlay Logs). Fetcher HTTP injecté :
 * aucun réseau réel, seulement la base de test.
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

    public function test_erreur_reseau_ecrit_un_cache_d_indisponibilite_court(): void
    {
        $calls = 0;
        $resolver = $this->resolver([self::SID64_PSYCHO => null], $calls);

        // Premier essai : échec réseau, aucune résolution.
        $this->assertSame([], $resolver->resolve([self::SID2_PSYCHO]));
        $this->assertSame(1, $calls);

        // Deuxième résolution dans la fenêtre du cache d'indisponibilité
        // (60 s) : l'API n'est pas martelée — un overlay OBS qui se
        // recharge toutes les minutes ne doit pas relancer les appels.
        $this->assertSame([], $resolver->resolve([self::SID2_PSYCHO]));
        $this->assertSame(1, $calls);

        // Entrée d'indisponibilité écrite (clé privée UNAVAILABLE_ERROR).
        $row = DB::table('etf2l_api_cache')
            ->where('url', 'https://api-v2.etf2l.org/player/'.self::SID64_PSYCHO)
            ->first();
        $this->assertNotNull($row);
        $payload = json_decode((string) $row->payload, true);
        $this->assertSame('hlfr_etf2l_indisponible', $payload['error'] ?? null);
    }

    public function test_le_cache_d_indisponibilite_expire_relance_l_api(): void
    {
        $this->seedApiCache(self::SID64_PSYCHO, ['error' => 'hlfr_etf2l_indisponible'], 61);

        $calls = 0;
        $resolver = $this->resolver([self::SID64_PSYCHO => $this->playerPayload('Psycho', 112835)], $calls);

        $resolved = $resolver->resolve([self::SID2_PSYCHO]);

        $this->assertSame(1, $calls);
        $this->assertSame(['name' => 'Psycho', 'etf2l_id' => 112835], $resolved[self::SID2_PSYCHO]);
    }

    public function test_un_payload_throttle_429_est_un_echec_mis_en_cache_court(): void
    {
        $throttle = ['status' => ['code' => 429, 'message' => 'Too Many Attempts.']];
        $calls = 0;
        $resolver = $this->resolver([self::SID64_PSYCHO => $throttle], $calls);

        $this->assertSame([], $resolver->resolve([self::SID2_PSYCHO]));
        $this->assertSame(1, $calls);

        // Cache d'indisponibilité écrit : pas de re-martèlement ensuite.
        $this->assertSame([], $resolver->resolve([self::SID2_PSYCHO]));
        $this->assertSame(1, $calls);

        $row = DB::table('etf2l_api_cache')
            ->where('url', 'https://api-v2.etf2l.org/player/'.self::SID64_PSYCHO)
            ->first();
        $payload = json_decode((string) $row->payload, true);
        $this->assertSame('hlfr_etf2l_indisponible', $payload['error'] ?? null);
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

    // ─── Équipes candidates d'un log (vote Overlay Logs) ──────────────────

    /**
     * Payload API player avec ses équipes.
     *
     * @param  array<int, array<string, mixed>>  $teams
     * @return array<string, mixed>
     */
    private function playerPayloadWithTeam(string $name, int $id, array $teams): array
    {
        $payload = $this->playerPayload($name, $id);
        $payload['player']['teams'] = $teams;

        return $payload;
    }

    /**
     * Entrée d'équipe d'un payload player (champs ajustables par test).
     *
     * @param  array<string, mixed>  $team
     * @return array<string, mixed>
     */
    private function apiTeam(array $team): array
    {
        return array_merge(['name' => 'Équipe', 'tag' => 'X', 'type' => 'Highlander Team'], $team);
    }

    /**
     * Amorce le roster synchronisé d'une équipe (etf2l_teams/etf2l_players).
     *
     * @param  array<int, string>  $steamids64
     */
    private function seedSyncedRoster(int $teamId, string $name, string $country, array $steamids64): void
    {
        DB::table('etf2l_teams')->insert([
            'team_id' => $teamId,
            'name' => $name,
            'country' => $country,
            'tag' => null,
        ]);

        foreach ($steamids64 as $steamid64) {
            DB::table('etf2l_players')->insert([
                'team_id' => $teamId,
                'player_id' => random_int(1000, 999999),
                'name' => 'joueur',
                'steamid64' => $steamid64,
            ]);
        }
    }

    public function test_team_candidates_combine_rosters_sync_et_payload_api(): void
    {
        // Roster synchronisé : équipe de club (le nom diffère du pays),
        // sans avatar connu — l'identifiant permet de le récupérer.
        $this->seedSyncedRoster(900, 'Les Baguettes', 'France', [self::SID64_PSYCHO]);

        // Payload API en cache : équipe de club Highlander courante (avec
        // son avatar), devant une équipe nationale ignorée.
        $this->seedApiCache(self::SID64_AUTRE, $this->playerPayloadWithTeam('Autre', 42, [
            $this->apiTeam(['name' => "\n France", 'type' => 'National Highlander Team']),
            $this->apiTeam(['name' => 'The Piece of Pie', 'id' => 901, 'steam' => ['avatar' => 'https://etf2l.org/pie.jpg']]),
        ]));

        $resolved = (new Etf2lNameResolver)->teamCandidatesBySteamId3([
            SteamId::toSteamId3(self::SID64_PSYCHO),
            SteamId::toSteamId3(self::SID64_AUTRE),
        ]);

        $this->assertSame(
            [['name' => 'Les Baguettes', 'avatar' => null, 'team_id' => 900]],
            $resolved[SteamId::toSteamId3(self::SID64_PSYCHO)]
        );
        $this->assertSame(
            [['name' => 'The Piece of Pie', 'avatar' => 'https://etf2l.org/pie.jpg', 'team_id' => 901]],
            $resolved[SteamId::toSteamId3(self::SID64_AUTRE)]
        );
    }

    public function test_team_candidates_exclut_les_equipes_nationales_des_rosters(): void
    {
        // « France » : le nom d'une équipe nationale est celui de son
        // pays — l'heuristique nom/pays l'écarte du vote.
        $this->seedSyncedRoster(15176, "\n France", ' France ', [self::SID64_PSYCHO]);

        $resolved = (new Etf2lNameResolver)->teamCandidatesBySteamId3([
            SteamId::toSteamId3(self::SID64_PSYCHO),
        ]);

        $this->assertArrayNotHasKey(SteamId::toSteamId3(self::SID64_PSYCHO), $resolved);
    }

    public function test_team_candidates_ignore_le_cache_perime_et_les_erreurs(): void
    {
        // Payload trouvé mais périmé (au-delà des 24 h).
        $this->seedApiCache(
            self::SID64_PSYCHO,
            $this->playerPayloadWithTeam('Psycho', 112835, [$this->apiTeam(['name' => 'Les Baguettes'])]),
            25 * 3600,
        );

        // Cache d'indisponibilité récent : aucune équipe à en tirer.
        $this->seedApiCache(self::SID64_AUTRE, ['error' => 'hlfr_etf2l_indisponible']);

        $resolved = (new Etf2lNameResolver)->teamCandidatesBySteamId3([
            SteamId::toSteamId3(self::SID64_PSYCHO),
            SteamId::toSteamId3(self::SID64_AUTRE),
        ]);

        $this->assertSame([], $resolved);
    }

    public function test_team_candidates_deduplique_les_noms_par_joueur(): void
    {
        // Deux équipes aux noms identiques (après assainissement) pour un
        // même joueur : un seul candidat, le premier identifiant l'emporte.
        $this->seedSyncedRoster(900, ' Les Baguettes ', 'France', [self::SID64_PSYCHO]);
        $this->seedSyncedRoster(901, 'Les Baguettes', 'France', [self::SID64_PSYCHO]);

        $resolved = (new Etf2lNameResolver)->teamCandidatesBySteamId3([
            SteamId::toSteamId3(self::SID64_PSYCHO),
        ]);

        $this->assertSame(
            [['name' => 'Les Baguettes', 'avatar' => null, 'team_id' => 900]],
            $resolved[SteamId::toSteamId3(self::SID64_PSYCHO)]
        );
    }

    public function test_les_pseudos_sont_dechappes_de_leurs_entites_html(): void
    {
        // L'API ETF2L renvoie des noms échappés pour le HTML : sans
        // décodage, le jeu et les overlays afficheraient « &#039; » tel quel.
        $resolved = $this->resolver([self::SID64_PSYCHO => $this->playerPayload('Psy&#039;cho &amp; Cie', 112835)])
            ->resolve([self::SID2_PSYCHO]);

        $this->assertSame("Psy'cho & Cie", $resolved[self::SID2_PSYCHO]['name']);
    }

    public function test_team_candidates_dechappe_les_noms_d_equipes(): void
    {
        $this->seedSyncedRoster(900, 'l&#039;équipe', 'France', [self::SID64_PSYCHO]);

        $resolved = (new Etf2lNameResolver)->teamCandidatesBySteamId3([
            SteamId::toSteamId3(self::SID64_PSYCHO),
        ]);

        $this->assertSame("l'équipe", $resolved[SteamId::toSteamId3(self::SID64_PSYCHO)][0]['name']);
    }

    // ─── Avatar d'une équipe (logo du club) ───────────────────────────────

    /**
     * Payload de l'endpoint équipe (/team/{id}), même cache que
     * Etf2lTeamService::roster.
     *
     * @return array<string, mixed>
     */
    private function teamEndpointPayload(int $teamId, string $avatar): array
    {
        return [
            'status' => ['code' => 200, 'message' => 'OK'],
            'team' => [
                'id' => $teamId,
                'name' => 'Les Baguettes',
                'steam' => ['avatar' => $avatar, 'steam_group' => null],
                'players' => [],
            ],
        ];
    }

    public function test_team_avatar_lit_le_cache_de_l_endpoint_equipe(): void
    {
        // Cache amorcé par un roster assisté (Etf2lTeamService) : l'avatar
        // se lit sans aucun appel HTTP.
        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api-v2.etf2l.org/team/900',
            'payload' => json_encode($this->teamEndpointPayload(900, 'https://etf2l.org/logo.jpg'), JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ]);

        $calls = 0;
        $resolver = new Etf2lNameResolver(static function (string $url) use (&$calls): ?array {
            $calls++;

            return null;
        });

        $this->assertSame('https://etf2l.org/logo.jpg', $resolver->teamAvatar(900));
        $this->assertSame(0, $calls);
    }

    public function test_team_avatar_interroge_l_api_puis_met_en_cache(): void
    {
        $calls = 0;
        $resolver = new Etf2lNameResolver(function (string $url) use (&$calls): ?array {
            $calls++;

            return str_contains($url, '/team/900')
                ? $this->teamEndpointPayload(900, 'https://etf2l.org/logo.jpg')
                : null;
        });

        $this->assertSame('https://etf2l.org/logo.jpg', $resolver->teamAvatar(900));
        $this->assertSame(1, $calls);

        // Second appel servi par le cache : plus de requête HTTP.
        $this->assertSame('https://etf2l.org/logo.jpg', $resolver->teamAvatar(900));
        $this->assertSame(1, $calls);
    }

    public function test_team_avatar_sans_avatar_renvoie_null(): void
    {
        $calls = 0;
        $resolver = new Etf2lNameResolver(function (string $url) use (&$calls): ?array {
            $calls++;

            return $this->teamEndpointPayload(900, '');
        });

        $this->assertNull($resolver->teamAvatar(900));

        // Une équipe sans avatar reste en cache : elle n'est pas
        // re-questionnée à chaque chargement de log.
        $this->assertNull($resolver->teamAvatar(900));
        $this->assertSame(1, $calls);
    }
}
