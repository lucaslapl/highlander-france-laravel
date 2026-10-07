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
 *  - « manual » : un point marqué saisi à la main par un admin (scoring live
 *                 d'une map à log unique en attendant son log, contestation,
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
 *  - « single » (KOTH, 5cp, payload race : un seul log) : le log ne remonte
 *    qu'à la fin de la map et porte son score final — score inégal → map
 *    décidée pour le meneur (winlimit atteint ou timelimit écoulé), égalité
 *    au timelimit (ex. 2-2 en 5cp à 30 min) → golden cap à jouer, map en
 *    attente du log de départage. En attendant le log, les points manuels
 *    s'accumulent en score live : la map n'est décidée qu'au winlimit
 *    atteint — 3 points en KOTH et payload race (mp_winlimit 3, pas de
 *    golden cap possible), 5 en 5cp (mp_winlimit 5, mp_timelimit 30).
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
     * Nombre de points qui décident une map à log unique (mp_winlimit du
     * serveur de match, configs ETF2L 9v9) : 3 en KOTH et payload race
     * (koth_/plr_/tow_, timelimit 0 — une égalité y est impossible), 5 en
     * 5cp et assimilés (mp_timelimit 30 : au timelimit, le meneur l'emporte
     * et une égalité part en golden cap, voir compute).
     */
    public static function deriveWinlimit(string $map): int
    {
        $map = strtolower(trim($map));

        foreach (['koth_', 'plr_', 'tow_'] as $prefix) {
            if (str_starts_with($map, $prefix)) {
                return 3;
            }
        }

        return 5;
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
                'winlimit' => $declared['winlimit'],
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
                $logScores = $this->seriesScores($event);
                $winner = (string) ($event['winner'] ?? '');

                if ($map['mode'] === self::MODE_DOUBLE) {
                    $map['scores'] = $logScores;
                    if ($winner === 'red' || $winner === 'blue') {
                        $map['rounds'][$winner]++;
                    }
                    if ($winner !== '' && $map['rounds'][$winner] >= 2) {
                        $map['status'] = 'decided';
                        $map['winner'] = $winner;
                    } elseif ($map['rounds']['red'] === 1 && $map['rounds']['blue'] === 1) {
                        $map['golden_cap'] = true;
                    }
                } else {
                    // Log unique : le log ne remonte qu'à la fin de la map et
                    // porte son score final — le meneur (winlimit atteint ou
                    // timelimit écoulé) l'emporte. Une égalité au timelimit
                    // (ex. 2-2 en 5cp à 30 min) laisse la map en attente de
                    // golden cap : celui-ci se joue après rechargement de la
                    // map (config ETF2L mp_winlimit 1), son log ne porte donc
                    // que le point du départage et s'ajoute à l'égalité.
                    if ($map['golden_cap'] && $map['scores'] !== null && $logScores !== null) {
                        $map['scores']['red'] += $logScores['red'];
                        $map['scores']['blue'] += $logScores['blue'];
                    } else {
                        $map['scores'] = $logScores;
                    }

                    if ($winner !== '') {
                        $map['status'] = 'decided';
                        $map['winner'] = $winner;
                        $map['golden_cap'] = false;
                    } elseif ($map['scores'] !== null && $map['scores']['red'] === $map['scores']['blue']) {
                        $map['golden_cap'] = true;
                    }
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
                    // Log unique : le point manuel est un point marqué en
                    // direct (le score du log ne remonte qu'à la fin de la
                    // map). Il s'accumule en score live et la map n'est
                    // décidée qu'au winlimit (KOTH : 3, 5cp : 5) — sauf golden
                    // cap en attente, que le point tranche.
                    if ($map['scores'] === null) {
                        $map['scores'] = ['red' => 0, 'blue' => 0];
                    }
                    $map['scores'][$team]++;

                    if ($map['golden_cap'] || $map['scores'][$team] >= $map['winlimit']) {
                        $map['status'] = 'decided';
                        $map['winner'] = $team;
                    }
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
     * Le winlimit ne concerne que les maps à log unique (nombre de points
     * qui décident la map) ; en double attaque, il vaut les 2 rounds requis.
     *
     * @param  array<string, mixed>  $series
     * @return array<int, array{name: string, mode: string, winlimit: int}>
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

            $winlimit = $mode === self::MODE_SINGLE ? self::deriveWinlimit($name) : 2;

            $declared[] = ['name' => $name, 'mode' => $mode, 'winlimit' => $winlimit];
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
