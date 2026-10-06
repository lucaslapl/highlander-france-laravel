<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Calcul de l'état d'une série de matchs (playoffs) à partir de son journal
 * d'événements (voir SeriesRepository::appendEvent).
 *
 * Le service est pur : il ne fait ni réseau ni I/O, uniquement du calcul sur
 * le payload de la série. Trois types d'événements composent le journal :
 *
 *  - « log »    : un log logs.tf rattaché à une map de la série, avec son
 *                 gagnant normalisé en équipe de série (« red »/« blue »,
 *                 indépendamment de la couleur en jeu) — événements produits
 *                 par le réconciliateur logs.tf (SeriesReconcileService) ;
 *  - « manual » : un point de map saisi à la main par un admin (contestation,
 *                 log manquant, rattrapage) ;
 *  - « void »   : annulation d'un événement (cible « target ») — l'événement
 *                 annulé reste dans le journal pour l'audit mais ne compte plus.
 *
 * Règles d'agrégation par map (formats ETF2L Highlander) :
 *
 *  - « double » (payload pl_* et A/D type cp_steel : double attaque, une par
 *    équipe) : chaque log = un round gagné (1-0 / 0-1). La map est décidée à
 *    2 rounds pour une équipe ; 1-1 reste en attente de golden cap (3e log) ;
 *    le score final d'une telle map est donc 2-0, 2-1 ou 1-1 (non décidée).
 *  - « single » (5cp, KOTH : un seul log) : la map est décidée par le log au
 *    score de rounds le plus élevé ; une égalité (stalemate) laisse la map en
 *    attente jusqu'à un log de départage (golden cap) ou un point manuel.
 *
 * Toute équipe étant susceptible de changer de couleur entre les logs (les
 * équipes s'échangent RED/BLU d'une moitié à l'autre d'un payload), les
 * événements ne portent jamais de couleur : uniquement des clés d'équipe de
 * série, recalculées par le réconciliateur à chaque log.
 */
final class SeriesScoreService
{
    public const MODE_DOUBLE = 'double';

    public const MODE_SINGLE = 'single';

    /** Maps A/D (double attaque) connues du pool HL, hors préfixe pl_. */
    private const AD_MAPS = ['cp_steel', 'cp_gravelpit', 'cp_gorge', 'cp_junction', 'cp_furnace', 'cp_canaveral'];

    /**
     * Mode d'agrégation par défaut d'une map : payload et A/D connus en
     * double attaque, tout le reste (5cp, KOTH) en log unique.
     */
    public static function deriveMode(string $map): string
    {
        $map = strtolower(trim($map));
        if (str_starts_with($map, 'pl_')) {
            return self::MODE_DOUBLE;
        }

        foreach (self::AD_MAPS as $ad) {
            if ($map === $ad || str_starts_with($map, $ad.'_')) {
                return self::MODE_DOUBLE;
            }
        }

        return self::MODE_SINGLE;
    }

    /**
     * Rattache le nom de map d'un log logs.tf (souvent suffixé d'une version,
     * ex. « cp_steel_f12 ») à la map déclarée correspondante de la série.
     * La correspondance la plus longue gagne (« cp_steel » pour « cp_steel_f12 »).
     *
     * @param  array<string, mixed>  $series
     */
    public static function matchSeriesMap(array $series, string $logMap): ?string
    {
        $logMap = strtolower(trim($logMap));
        $best = null;
        $bestLen = -1;

        foreach (self::seriesMapNames($series) as $name) {
            if (($name === $logMap || str_starts_with($logMap, $name.'_') || str_starts_with($logMap, $name)) && strlen($name) > $bestLen) {
                $best = $name;
                $bestLen = strlen($name);
            }
        }

        return $best;
    }

    /**
     * État complet d'une série : état par map, score de série, achèvement.
     *
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>
     */
    public function compute(array $series): array
    {
        $voided = $this->voidedEventIds($series);
        $journal = is_array($series['journal'] ?? null) ? $series['journal'] : [];

        $maps = [];
        foreach ($this->declaredMaps($series) as $declared) {
            $maps[$declared['name']] = [
                'name' => $declared['name'],
                'mode' => $declared['mode'],
                'status' => 'pending',
                'winner' => null,
                'rounds' => ['red' => 0, 'blue' => 0],
                'scores' => null,
                'log_ids' => [],
                'golden_cap' => false,
            ];
        }

        foreach ($journal as $event) {
            $id = (string) ($event['id'] ?? '');
            if ($id === '' || isset($voided[$id])) {
                continue;
            }

            $type = (string) ($event['type'] ?? '');
            $mapName = (string) ($event['map'] ?? '');
            if (! isset($maps[$mapName])) {
                continue;
            }

            $map = &$maps[$mapName];

            // Une map décidée ignore les événements surnuméraires (log rejoué,
            // upload en double déjà dédupé par log_id côté réconciliateur).
            if ($map['status'] === 'decided') {
                continue;
            }

            if ($type === 'log') {
                $map['log_ids'][] = (int) ($event['log_id'] ?? 0);
                $map['scores'] = $this->seriesScores($event);
                $winner = (string) ($event['winner'] ?? '');

                if ($map['mode'] === self::MODE_DOUBLE) {
                    if ($winner === 'red' || $winner === 'blue') {
                        $map['rounds'][$winner]++;
                    }
                    if ($winner !== '' && $map['rounds'][$winner] >= 2) {
                        $map['status'] = 'decided';
                        $map['winner'] = $winner;
                    } elseif ($map['rounds']['red'] === 1 && $map['rounds']['blue'] === 1) {
                        $map['golden_cap'] = true;
                    }
                } elseif ($winner !== '') {
                    // Log unique : le gagnant au score de rounds décide la map.
                    // Une égalité laisse la map en attente de départage.
                    $map['rounds'][$winner] = 1;
                    $map['status'] = 'decided';
                    $map['winner'] = $winner;
                }
            } elseif ($type === 'manual') {
                $team = (string) ($event['team'] ?? '');
                if ($team !== 'red' && $team !== 'blue') {
                    continue;
                }

                if ($map['mode'] === self::MODE_DOUBLE) {
                    $map['rounds'][$team]++;
                    if ($map['rounds'][$team] >= 2) {
                        $map['status'] = 'decided';
                        $map['winner'] = $team;
                    } elseif ($map['rounds']['red'] === 1 && $map['rounds']['blue'] === 1) {
                        $map['golden_cap'] = true;
                    }
                } else {
                    $map['rounds'][$team] = 1;
                    $map['status'] = 'decided';
                    $map['winner'] = $team;
                }
            }
            unset($map);
        }

        $maps = array_values($maps);

        $score = ['red' => 0, 'blue' => 0];
        foreach ($maps as $map) {
            if ($map['status'] === 'decided' && $map['winner'] !== null) {
                $score[(string) $map['winner']]++;
            }
        }

        $winsNeeded = array_key_exists('wins_needed', $series) ? $series['wins_needed'] : null;
        $allDecided = $maps !== [] && count(array_filter($maps, static fn (array $m): bool => $m['status'] !== 'decided')) === 0;

        // BO3/BO5 : fin au nombre de maps gagnées atteint (les maps déclarées
        // peuvent être moins nombreuses que le format — maps restantes
        // inconnues à la création). Maps fixes : fin quand toutes sont jouées.
        $finished = $winsNeeded !== null
            ? max($score) >= (int) $winsNeeded
            : $allDecided;

        $winner = null;
        if ($finished && $score['red'] !== $score['blue']) {
            $winner = $score['red'] > $score['blue'] ? 'red' : 'blue';
        }

        return [
            'maps' => $maps,
            'score' => $score,
            'finished' => $finished,
            'winner' => $winner,
        ];
    }

    /**
     * Identifiants des événements annulés (« void ») : leurs cibles ne
     * comptent plus dans le calcul, tout en restant visibles dans l'audit.
     *
     * @param  array<string, mixed>  $series
     * @return array<string, true>
     */
    private function voidedEventIds(array $series): array
    {
        $voided = [];
        foreach (is_array($series['journal'] ?? null) ? $series['journal'] : [] as $event) {
            if (($event['type'] ?? '') === 'void') {
                $target = (string) ($event['target'] ?? '');
                if ($target !== '') {
                    $voided[$target] = true;
                }
            }
        }

        return $voided;
    }

    /**
     * Maps déclarées de la série, mode explicite ou déduit par préfixe.
     *
     * @param  array<string, mixed>  $series
     * @return array<int, array{name: string, mode: string}>
     */
    private function declaredMaps(array $series): array
    {
        $declared = [];
        foreach (is_array($series['maps'] ?? null) ? $series['maps'] : [] as $map) {
            $name = strtolower(trim((string) ($map['name'] ?? '')));
            if ($name === '') {
                continue;
            }

            $mode = (string) ($map['mode'] ?? '');
            if ($mode !== self::MODE_DOUBLE && $mode !== self::MODE_SINGLE) {
                $mode = self::deriveMode($name);
            }

            $declared[] = ['name' => $name, 'mode' => $mode];
        }

        return $declared;
    }

    /**
     * @param  array<string, mixed>  $series
     * @return array<int, string>
     */
    private static function seriesMapNames(array $series): array
    {
        $names = [];
        foreach (is_array($series['maps'] ?? null) ? $series['maps'] : [] as $map) {
            $name = strtolower(trim((string) ($map['name'] ?? '')));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Scores d'un événement « log », normalisés en équipes de série.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, int>|null
     */
    private function seriesScores(array $event): ?array
    {
        $scores = $event['scores'] ?? null;
        if (! is_array($scores)) {
            return null;
        }

        return [
            'red' => (int) ($scores['red'] ?? 0),
            'blue' => (int) ($scores['blue'] ?? 0),
        ];
    }
}
