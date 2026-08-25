<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Formatage et structuration des données liées aux matchs / logs.
 */
final class MatchFormat
{
    /**
     * Nom lisible d'une carte (ou combinaison de cartes).
     * Ex. : "cp_process_final" -> "Process".
     */
    public static function mapDisplay(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '—';
        }

        $names = [];
        foreach (preg_split('/\s*\+\s*/', $raw) as $p) {
            $p = preg_replace('/_(final|rc|v|b|f)\d*$/i', '', $p);
            $p = preg_replace('/^(koth|cp|pl|plr|ctf|td|dom|tc|arena|mvm|sd|pass|rd|pd|vsh|ph|zr|dr|slay)_/i', '', $p);
            $p = ucwords(preg_replace('/_/', ' ', trim($p)));
            if ($p !== '') {
                $names[] = $p;
            }
        }

        return implode(' + ', $names);
    }

    /**
     * Durée en minutes:secondes (intdiv). Retourne [0,0] pour une durée <= 0.
     *
     * @return array{min: int, sec: int}
     */
    public static function durationParts(int $seconds): array
    {
        if ($seconds <= 0) {
            return ['min' => 0, 'sec' => 0];
        }

        return ['min' => intdiv($seconds, 60), 'sec' => $seconds % 60];
    }

    /**
     * Formate une durée en minutes:secondes. Retourne null si <= 0.
     */
    public static function duration(int $seconds): ?string
    {
        if ($seconds <= 0) {
            return null;
        }

        $parts = self::durationParts($seconds);

        return sprintf('%d:%02d', $parts['min'], $parts['sec']);
    }

    /**
     * Résultat d'une équipe à partir des scores ("win" | "loss" | "draw" | null).
     */
    public static function teamResult(?int $score, ?int $otherScore): ?string
    {
        if ($score === null || $otherScore === null) {
            return null;
        }
        if ($score > $otherScore) {
            return 'win';
        }
        if ($score < $otherScore) {
            return 'loss';
        }

        return 'draw';
    }

    /**
     * Répartit les joueurs d'un match selon leur équipe.
     *
     * @param  array<int, array<string, mixed>> $players
     * @return array{
     *     red: array<int, array<string, mixed>>,
     *     blue: array<int, array<string, mixed>>,
     *     other: array<int, array<string, mixed>>,
     *     hasTeams: bool
     * }
     */
    public static function partitionPlayers(array $players): array
    {
        $red = [];
        $blue = [];
        $other = [];

        foreach ($players as $p) {
            $team = $p['team'] ?? null;
            if ($team === 'red') {
                $red[] = $p;
            } elseif ($team === 'blue') {
                $blue[] = $p;
            } else {
                $other[] = $p;
            }
        }

        return [
            'red' => $red,
            'blue' => $blue,
            'other' => $other,
            'hasTeams' => $red !== [] || $blue !== [],
        ];
    }
}