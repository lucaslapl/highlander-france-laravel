<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Construction du payload de stats d'overlay OBS depuis un log logs.tf.
 *
 * Interroge l'API logs.tf (/api/v1/log/{id}) et normalise la réponse en un
 * payload directement exploitable par la vue d'overlay : équipes, scores,
 * joueurs (avec classe jouée, K/A/D, dégâts, DPM, soins reçus, dégâts
 * subis, K/D), stats medics par équipe (heal total, ubers, drops, durée
 * moyenne des ubers) et mise en avant de la meilleure valeur de chaque
 * statistique.
 *
 * La récupération HTTP est injectable (closure) pour les tests sans réseau.
 * Aucun modèle Eloquent : le payload est persisté en JSON via
 * OverlayRepository (hlfr_data_path()).
 */
final class OverlayStatsService
{
    /** Colonnes de stats proposées pour la mise en avant du maximum. */
    private const BEST_STATS = ['kills', 'assists', 'deaths', 'dmg', 'dapm', 'hr', 'dt', 'kd'];

    /**
     * Construit le payload d'overlay pour un log logs.tf.
     *
     * @param  callable(int): ?array  $fetcher  Injecté pour les tests : renvoie la réponse décodée de l'API.
     * @return array<string, mixed>|null Payload normalisé, ou null si le log est introuvable/invalide.
     */
    public function buildPayload(int $logId, ?callable $fetcher = null): ?array
    {
        $fetcher ??= static fn (int $id): ?array => JsonClient::get('https://logs.tf/api/v1/log/'.$id);
        $details = $fetcher($logId);

        if (! is_array($details)
            || ($details['success'] ?? false) !== true
            || ! is_array($details['players'] ?? null)
            || $details['players'] === []) {
            return null;
        }

        $names = is_array($details['names'] ?? null) ? $details['names'] : [];
        $teamsRaw = is_array($details['teams'] ?? null) ? $details['teams'] : [];
        $length = (int) ($details['length'] ?? 0);

        $players = ['red' => [], 'blue' => []];

        foreach ($details['players'] as $steamid3 => $pData) {
            $team = strtolower((string) ($pData['team'] ?? ''));
            if ($team !== 'red' && $team !== 'blue') {
                continue;
            }

            $deaths = (int) ($pData['deaths'] ?? 0);
            $kills = (int) ($pData['kills'] ?? 0);
            $kd = $deaths > 0 ? round($kills / $deaths, 2) : (float) $kills;

            $players[$team][] = [
                'steamid3' => (string) $steamid3,
                'name' => (string) ($names[(string) $steamid3] ?? (string) $steamid3),
                'class' => $this->playedClass(is_array($pData['class_stats'] ?? null) ? $pData['class_stats'] : []),
                'kills' => $kills,
                'assists' => (int) ($pData['assists'] ?? 0),
                'deaths' => $deaths,
                'dmg' => (int) ($pData['dmg'] ?? 0),
                'dapm' => (int) ($pData['dapm'] ?? 0),
                'hr' => (int) ($pData['hr'] ?? 0),
                'dt' => (int) ($pData['dt'] ?? 0),
                'kd' => $kd,
                'best' => [],
            ];
        }

        if ($players['red'] === [] && $players['blue'] === []) {
            return null;
        }

        $this->sortByClassOrder($players['red']);
        $this->sortByClassOrder($players['blue']);
        $this->markBestStats($players);

        $info = is_array($details['info'] ?? null) ? $details['info'] : [];

        return [
            'log_id' => $logId,
            'title' => (string) ($info['title'] ?? ''),
            'map' => (string) ($info['map'] ?? ''),
            'date' => (int) ($info['date'] ?? 0),
            'length' => $length,
            'teams' => [
                'red' => [
                    'name' => 'RED',
                    'score' => (int) ($teamsRaw['Red']['score'] ?? 0),
                ],
                'blue' => [
                    'name' => 'BLU',
                    'score' => (int) ($teamsRaw['Blue']['score'] ?? $teamsRaw['BLU']['score'] ?? 0),
                ],
            ],
            'players' => $players,
            'medics' => $this->medicStats($details['players'], $players),
        ];
    }

    /**
     * Classe jouée principale : l'entrée class_stats avec le plus de dégâts
     * (Highlander = une classe, mais le log peut contenir des substitutions).
     *
     * @param  array<int, array<string, mixed>>  $classStats
     */
    private function playedClass(array $classStats): string
    {
        $best = null;
        $bestDmg = -1;

        foreach ($classStats as $cs) {
            if (! is_array($cs)) {
                continue;
            }

            $dmg = (int) ($cs['dmg'] ?? 0);
            if ($dmg > $bestDmg) {
                $best = $cs;
                $bestDmg = $dmg;
            }
        }

        if ($best === null && $classStats !== []) {
            $best = $classStats[0];
        }

        return strtolower((string) (is_array($best) ? ($best['type'] ?? '') : ''));
    }

    /**
     * Trie les joueurs par ordre de classe Highlander France puis par pseudo.
     *
     * @param  array<int, array<string, mixed>>  $teamPlayers
     */
    private function sortByClassOrder(array &$teamPlayers): void
    {
        $order = array_keys(config('hlfr.tf2_classes', []));

        usort($teamPlayers, static function (array $a, array $b) use ($order): int {
            $ia = array_search($a['class'], $order, true);
            $ib = array_search($b['class'], $order, true);

            $ia = $ia === false ? 99 : (int) $ia;
            $ib = $ib === false ? 99 : (int) $ib;

            return $ia <=> $ib ?: strcasecmp((string) $a['name'], (string) $b['name']);
        });
    }

    /**
     * Marque la meilleure valeur de chaque stat (les deux équipes confondues).
     *
     * @param  array<string, array<int, array<string, mixed>>>  $players
     */
    private function markBestStats(array &$players): void
    {
        foreach (self::BEST_STATS as $stat) {
            $bestTeam = null;
            $bestIndex = null;
            $bestValue = null;

            foreach (['red', 'blue'] as $team) {
                foreach ($players[$team] as $index => $player) {
                    $value = $player[$stat];
                    if ($bestValue === null || (is_float($value) || is_int($value)) && $value > $bestValue) {
                        $bestValue = $value;
                        $bestTeam = $team;
                        $bestIndex = $index;
                    }
                }
            }

            if ($bestTeam !== null && $bestIndex !== null && (float) $bestValue > 0) {
                $players[$bestTeam][$bestIndex]['best'][] = $stat;
            }
        }
    }

    /**
     * Agrégats medics par équipe : heal total, nombre d'ubers, nombre de
     * drops et durée moyenne des ubers (moyenne pondérée par le nombre
     * d'ubers de chaque medic).
     *
     * @param  array<string, array<string, mixed>>  $rawPlayers
     * @param  array<string, array<int, array<string, mixed>>>  $normalized
     * @return array<string, array<string, mixed>>
     */
    private function medicStats(array $rawPlayers, array $normalized): array
    {
        $stats = [
            'red' => ['heal' => 0, 'ubers' => 0, 'drops' => 0, 'avg_uber_length' => null, 'count' => 0],
            'blue' => ['heal' => 0, 'ubers' => 0, 'drops' => 0, 'avg_uber_length' => null, 'count' => 0],
        ];

        foreach ($normalized as $team => $teamPlayers) {
            foreach ($teamPlayers as $player) {
                if ($player['class'] !== 'medic') {
                    continue;
                }

                $raw = $rawPlayers[$player['steamid3']] ?? [];
                $medicStats = is_array($raw['medicstats'] ?? null) ? $raw['medicstats'] : [];
                $ubers = (int) ($raw['ubers'] ?? 0);

                $stats[$team]['heal'] += (int) ($raw['heal'] ?? 0);
                $stats[$team]['ubers'] += $ubers;
                $stats[$team]['drops'] += (int) ($raw['drops'] ?? 0);
                $stats[$team]['count']++;

                $avgLength = $medicStats['avg_uber_length'] ?? null;
                if ($avgLength !== null && $ubers > 0) {
                    // Somme pondérée par le nombre d'ubers de chaque medic,
                    // divisée par le total à la fin (moyenne des durées).
                    $stats[$team]['avg_uber_length'] = ($stats[$team]['avg_uber_length'] ?? 0) + (float) $avgLength * $ubers;
                }
            }
        }

        foreach (['red', 'blue'] as $team) {
            if ($stats[$team]['ubers'] > 0 && $stats[$team]['avg_uber_length'] !== null) {
                $stats[$team]['avg_uber_length'] = round($stats[$team]['avg_uber_length'] / $stats[$team]['ubers'], 1);
            } else {
                $stats[$team]['avg_uber_length'] = null;
            }
        }

        return $stats;
    }
}
