<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SeriesScoreService;
use Tests\TestCase;

/**
 * Machine à états du score d'une série (SeriesScoreService) : agrégation des
 * événements du journal par map — double attaque (pl_/A/D : 2 rounds par map,
 * golden cap sur 1-1), log unique (KOTH à 3 points, 5cp à 5 : points manuels
 * en score live, golden cap sur égalité au timelimit), annulations et
 * achèvement de la série (BO3/BO5/maps fixes).
 */
class SeriesScoreServiceTest extends TestCase
{
    // ─── Modes et rattachement de map ─────────────────────────────────────

    public function test_le_mode_derive_double_attaque_pour_payload_et_ad(): void
    {
        $this->assertSame(SeriesScoreService::MODE_DOUBLE, SeriesScoreService::deriveMode('pl_upward_f10'));
        $this->assertSame(SeriesScoreService::MODE_DOUBLE, SeriesScoreService::deriveMode('cp_steel_f12'));
        $this->assertSame(SeriesScoreService::MODE_DOUBLE, SeriesScoreService::deriveMode('cp_gravelpit_rc8'));
    }

    public function test_le_mode_derive_log_unique_pour_5cp_et_koth(): void
    {
        $this->assertSame(SeriesScoreService::MODE_SINGLE, SeriesScoreService::deriveMode('koth_product_final'));
        $this->assertSame(SeriesScoreService::MODE_SINGLE, SeriesScoreService::deriveMode('cp_process_f7'));
        $this->assertSame(SeriesScoreService::MODE_SINGLE, SeriesScoreService::deriveMode('cp_sunshine'));
    }

    public function test_le_winlimit_derive_3_points_en_koth_plr_et_tow_5_sinon(): void
    {
        $this->assertSame(3, SeriesScoreService::deriveWinlimit('koth_product_final'));
        $this->assertSame(3, SeriesScoreService::deriveWinlimit('koth_lakeside_final'));
        $this->assertSame(3, SeriesScoreService::deriveWinlimit('plr_bananabay'));
        $this->assertSame(3, SeriesScoreService::deriveWinlimit('tow_tetsudo_b10c'));
        $this->assertSame(5, SeriesScoreService::deriveWinlimit('cp_process_f7'));
        $this->assertSame(5, SeriesScoreService::deriveWinlimit('cp_gullywash_f7'));
    }

    public function test_une_map_de_log_est_rattachee_au_prefixe_declare(): void
    {
        $series = $this->series(['cp_steel_f12', 'koth_product_final']);

        $this->assertSame('cp_steel_f12', SeriesScoreService::matchSeriesMap($series, 'cp_steel_f12'));
        $this->assertSame('cp_steel_f12', SeriesScoreService::matchSeriesMap($series, 'cp_steel_f12b'));
        $this->assertSame('koth_product_final', SeriesScoreService::matchSeriesMap($series, 'koth_product_final'));
        $this->assertNull(SeriesScoreService::matchSeriesMap($series, 'pl_upward'));
    }

    // ─── Maps en double attaque ───────────────────────────────────────────

    public function test_payload_2_0_decide_la_map_des_le_deuxieme_log(): void
    {
        $series = $this->series(['pl_upward'], 'bo3', 2, [
            $this->logEvent('e1', 'pl_upward', 'red', 1, 0),
            $this->logEvent('e2', 'pl_upward', 'red', 1, 0),
        ]);

        $state = (new SeriesScoreService)->compute($series);

        $map = $state['maps'][0];
        $this->assertSame('decided', $map['status']);
        $this->assertSame('red', $map['winner']);
        $this->assertSame(2, $map['rounds']['red']);
        $this->assertSame(0, $map['rounds']['blue']);
        $this->assertFalse($map['golden_cap']);
        $this->assertSame(1, $state['score']['red']);
        $this->assertFalse($state['finished']);
    }

    public function test_payload_1_1_attend_le_golden_cap_puis_decide_2_1(): void
    {
        $series = $this->series(['cp_steel_f12'], 'bo3', 2, [
            $this->logEvent('e1', 'cp_steel_f12', 'red', 1, 0),
            $this->logEvent('e2', 'cp_steel_f12', 'blue', 0, 1),
        ]);

        $pending = (new SeriesScoreService)->compute($series);
        $this->assertSame('pending', $pending['maps'][0]['status']);
        $this->assertTrue($pending['maps'][0]['golden_cap']);
        $this->assertSame(['red' => 0, 'blue' => 0], $pending['score']);

        $series['journal'][] = $this->logEvent('e3', 'cp_steel_f12', 'blue', 0, 1);
        $decided = (new SeriesScoreService)->compute($series);
        $this->assertSame('decided', $decided['maps'][0]['status']);
        $this->assertSame('blue', $decided['maps'][0]['winner']);
        $this->assertSame(1, $decided['score']['blue']);
    }

    public function test_un_log_surnumeraire_apres_decision_est_ignore(): void
    {
        $series = $this->series(['pl_upward'], 'bo3', 2, [
            $this->logEvent('e1', 'pl_upward', 'red', 1, 0),
            $this->logEvent('e2', 'pl_upward', 'red', 1, 0),
            $this->logEvent('e3', 'pl_upward', 'blue', 0, 1),
        ]);

        $state = (new SeriesScoreService)->compute($series);

        $this->assertSame(2, $state['maps'][0]['rounds']['red']);
        $this->assertSame(0, $state['maps'][0]['rounds']['blue']);
        $this->assertSame('red', $state['maps'][0]['winner']);
    }

    // ─── Maps en log unique ───────────────────────────────────────────────

    public function test_map_unique_decidee_au_score_de_rounds(): void
    {
        $series = $this->series(['koth_product_final'], 'bo3', 2, [
            $this->logEvent('e1', 'koth_product_final', 'blue', 2, 4),
        ]);

        $state = (new SeriesScoreService)->compute($series);

        $this->assertSame('decided', $state['maps'][0]['status']);
        $this->assertSame('blue', $state['maps'][0]['winner']);
        $this->assertSame(1, $state['score']['blue']);
    }

    public function test_map_unique_egalite_puis_departage(): void
    {
        // 5cp, mp_timelimit 30 : le log de fin de map tombe sur 4-4 → golden
        // cap à jouer, la map reste en attente jusqu'au log de départage.
        $series = $this->series(['cp_process_f7'], 'bo3', 2, [
            $this->logEvent('e1', 'cp_process_f7', null, 4, 4),
        ]);

        $pending = (new SeriesScoreService)->compute($series);
        $this->assertSame('pending', $pending['maps'][0]['status']);
        $this->assertNull($pending['maps'][0]['winner']);
        $this->assertTrue($pending['maps'][0]['golden_cap']);
        $this->assertSame(['red' => 0, 'blue' => 0], $pending['score']);

        $series['journal'][] = $this->logEvent('e2', 'cp_process_f7', 'red', 1, 0);
        $decided = (new SeriesScoreService)->compute($series);
        $this->assertSame('decided', $decided['maps'][0]['status']);
        $this->assertSame('red', $decided['maps'][0]['winner']);
        $this->assertFalse($decided['maps'][0]['golden_cap']);
        // Le golden cap se joue après rechargement de la map (config ETF2L
        // mp_winlimit 1) : son log ne porte que le point du départage, qui
        // s'ajoute à l'égalité du timelimit (4-4 + 1-0 → 5-4).
        $this->assertSame(5, $decided['maps'][0]['scores']['red']);
        $this->assertSame(4, $decided['maps'][0]['scores']['blue']);
    }

    // ─── Points manuels et annulations ────────────────────────────────────

    public function test_des_points_manuels_ne_decident_pas_une_map_koth_avant_trois(): void
    {
        // KOTH, mp_winlimit 3 : un point marqué en direct n'emporte pas la
        // map — la première équipe à 3 points la gagne.
        $series = $this->series(['koth_product_final'], 'bo3', 2, [
            $this->manualEvent('e1', 'koth_product_final', 'blue'),
            $this->manualEvent('e2', 'koth_product_final', 'blue'),
        ]);

        $ongoing = (new SeriesScoreService)->compute($series);
        $this->assertSame('pending', $ongoing['maps'][0]['status']);
        $this->assertNull($ongoing['maps'][0]['winner']);
        $this->assertSame(2, $ongoing['maps'][0]['scores']['blue']);
        $this->assertSame(0, $ongoing['maps'][0]['scores']['red']);
        $this->assertSame(['red' => 0, 'blue' => 0], $ongoing['score']);

        $series['journal'][] = $this->manualEvent('e3', 'koth_product_final', 'blue');
        $decided = (new SeriesScoreService)->compute($series);
        $this->assertSame('decided', $decided['maps'][0]['status']);
        $this->assertSame('blue', $decided['maps'][0]['winner']);
        $this->assertSame(3, $decided['maps'][0]['scores']['blue']);
        $this->assertSame(1, $decided['score']['blue']);
    }

    public function test_des_points_manuels_decident_une_map_5cp_a_cinq_points(): void
    {
        // 5cp, mp_winlimit 5 : la map n'est décidée qu'au cinquième point.
        $series = $this->series(['cp_gullywash_f7'], 'bo3', 2, [
            $this->manualEvent('e1', 'cp_gullywash_f7', 'red'),
            $this->manualEvent('e2', 'cp_gullywash_f7', 'red'),
            $this->manualEvent('e3', 'cp_gullywash_f7', 'red'),
            $this->manualEvent('e4', 'cp_gullywash_f7', 'red'),
        ]);

        $ongoing = (new SeriesScoreService)->compute($series);
        $this->assertSame('pending', $ongoing['maps'][0]['status']);

        $series['journal'][] = $this->manualEvent('e5', 'cp_gullywash_f7', 'red');
        $decided = (new SeriesScoreService)->compute($series);
        $this->assertSame('decided', $decided['maps'][0]['status']);
        $this->assertSame('red', $decided['maps'][0]['winner']);
        $this->assertSame(5, $decided['maps'][0]['scores']['red']);
    }

    public function test_un_point_manuel_tranche_un_golden_cap_5cp_en_attente(): void
    {
        // 5cp fini 2-2 au timelimit : golden cap joué, point manuel du caster
        // qui tranche le départage — le point s'ajoute au score du log.
        $series = $this->series(['cp_process_f7'], 'bo3', 2, [
            $this->logEvent('e1', 'cp_process_f7', null, 2, 2),
            $this->manualEvent('e2', 'cp_process_f7', 'red'),
        ]);

        $state = (new SeriesScoreService)->compute($series);

        $this->assertSame('decided', $state['maps'][0]['status']);
        $this->assertSame('red', $state['maps'][0]['winner']);
        $this->assertSame(3, $state['maps'][0]['scores']['red']);
        $this->assertSame(2, $state['maps'][0]['scores']['blue']);
    }

    public function test_le_log_de_fin_de_map_remplace_le_score_des_points_manuels(): void
    {
        // Le caster compte 2-1 en direct sur un KOTH, le log de fin de map
        // ramène le score final 3-1 : le log est autoritaire.
        $series = $this->series(['koth_product_final'], 'bo3', 2, [
            $this->manualEvent('e1', 'koth_product_final', 'red'),
            $this->manualEvent('e2', 'koth_product_final', 'red'),
            $this->manualEvent('e3', 'koth_product_final', 'blue'),
            $this->logEvent('e4', 'koth_product_final', 'red', 3, 1),
        ]);

        $state = (new SeriesScoreService)->compute($series);

        $this->assertSame('decided', $state['maps'][0]['status']);
        $this->assertSame('red', $state['maps'][0]['winner']);
        $this->assertSame(3, $state['maps'][0]['scores']['red']);
        $this->assertSame(1, $state['maps'][0]['scores']['blue']);
    }

    public function test_un_point_manuel_tranche_un_golden_cap_en_attente(): void
    {
        $series = $this->series(['cp_steel_f12'], 'bo3', 2, [
            $this->logEvent('e1', 'cp_steel_f12', 'red', 1, 0),
            $this->logEvent('e2', 'cp_steel_f12', 'blue', 0, 1),
            $this->manualEvent('e3', 'cp_steel_f12', 'red'),
        ]);

        $state = (new SeriesScoreService)->compute($series);

        $this->assertSame('decided', $state['maps'][0]['status']);
        $this->assertSame('red', $state['maps'][0]['winner']);
        $this->assertSame(2, $state['maps'][0]['rounds']['red']);
    }

    public function test_annuler_un_evenement_le_retire_du_calcul(): void
    {
        $series = $this->series(['koth_product_final'], 'bo3', 2, [
            $this->logEvent('e1', 'koth_product_final', 'red', 4, 2),
            $this->logEvent('e2', 'koth_product_final', 'blue', 1, 4),
            $this->voidEvent('e3', 'e2'),
        ]);

        $state = (new SeriesScoreService)->compute($series);

        $this->assertSame('red', $state['maps'][0]['winner']);
        $this->assertSame(1, $state['score']['red']);
    }

    // ─── Achèvement de la série ───────────────────────────────────────────

    public function test_bo3_terminee_a_deux_maps_gagnees(): void
    {
        $series = $this->series(['pl_upward', 'koth_product_final', 'cp_steel_f12'], 'bo3', 2, [
            $this->logEvent('e1', 'pl_upward', 'red', 1, 0),
            $this->logEvent('e2', 'pl_upward', 'red', 1, 0),
            $this->logEvent('e3', 'koth_product_final', 'red', 5, 4),
        ]);

        $state = (new SeriesScoreService)->compute($series);

        $this->assertSame(2, $state['score']['red']);
        $this->assertSame(0, $state['score']['blue']);
        $this->assertTrue($state['finished']);
        $this->assertSame('red', $state['winner']);
        // Le golden cap de la map 3 n'est pas joué : la série était déjà finie,
        // la map reste en attente mais ne bloque pas l'achèvement.
        $this->assertSame('pending', $state['maps'][2]['status']);
    }

    public function test_format_maps_fixes_termine_quand_toutes_sont_decidees(): void
    {
        $series = $this->series(['pl_upward', 'cp_steel_f12'], 'fixed', null, [
            $this->logEvent('e1', 'pl_upward', 'red', 1, 0),
            $this->logEvent('e2', 'pl_upward', 'blue', 0, 1),
            $this->logEvent('e3', 'pl_upward', 'red', 1, 0),
            $this->logEvent('e4', 'cp_steel_f12', 'red', 1, 0),
            $this->logEvent('e5', 'cp_steel_f12', 'blue', 0, 1),
        ]);

        // Le golden cap de la deuxième map n'est pas joué : pas encore finie.
        $ongoing = (new SeriesScoreService)->compute($series);
        $this->assertFalse($ongoing['finished']);
        $this->assertSame(1, $ongoing['score']['red']);

        $series['journal'][] = $this->logEvent('e6', 'cp_steel_f12', 'blue', 0, 1);
        $draw = (new SeriesScoreService)->compute($series);

        // Deux maps fixes à 1-1 : égalité possible, série finie sans vainqueur.
        $this->assertTrue($draw['finished']);
        $this->assertNull($draw['winner']);
        $this->assertSame(1, $draw['score']['red']);
        $this->assertSame(1, $draw['score']['blue']);
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────

    /**
     * Série minimale : deux équipes, des maps déclarées (mode déduit) et un
     * journal d'événements optionnel.
     *
     * @param  array<int, string>  $mapNames
     * @param  array<int, array<string, mixed>>  $journal
     * @return array<string, mixed>
     */
    private function series(array $mapNames, string $format = 'bo3', ?int $winsNeeded = 2, array $journal = []): array
    {
        $maps = [];
        foreach ($mapNames as $name) {
            $maps[] = ['name' => $name, 'mode' => SeriesScoreService::deriveMode($name)];
        }

        return [
            'token' => 'aaaaaaaaaaaaaaaa',
            'title' => 'Test : Rouges vs Bleus',
            'format' => $format,
            'wins_needed' => $winsNeeded,
            'status' => 'live',
            'started_at' => time() - 3600,
            'created_at' => time() - 4000,
            'teams' => [
                'red' => ['name' => 'Les Rouges', 'players' => []],
                'blue' => ['name' => 'Les Bleus', 'players' => []],
            ],
            'maps' => $maps,
            'journal' => $journal,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function logEvent(string $id, string $map, ?string $winner, int $redScore, int $blueScore): array
    {
        return [
            'id' => $id,
            'type' => 'log',
            'source' => 'logstf',
            'log_id' => random_int(100000, 999999),
            'map' => $map,
            'winner' => $winner,
            'scores' => ['red' => $redScore, 'blue' => $blueScore],
            'at' => time(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manualEvent(string $id, string $map, string $team): array
    {
        return [
            'id' => $id,
            'type' => 'manual',
            'source' => 'manual',
            'map' => $map,
            'team' => $team,
            'note' => '',
            'at' => time(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function voidEvent(string $id, string $target): array
    {
        return [
            'id' => $id,
            'type' => 'void',
            'source' => 'manual',
            'target' => $target,
            'at' => time(),
        ];
    }
}
