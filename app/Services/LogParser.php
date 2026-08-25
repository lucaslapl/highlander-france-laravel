<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Extraction des stats par joueur depuis la réponse complète d'un log logs.tf.
 * Port de extractLogPlayerStats() (legacy _inc/functions.php).
 */
final class LogParser
{
    /**
     * @param array<string, mixed> $details Réponse de /api/v1/log/<id>
     * @return array<string, array<string, mixed>> [steamid3 => [dmg, kills, ..., classes_killed]]
     */
    public static function extract(array $details): array
    {
        $classKills = $details['classkills'] ?? [];
        $stats = [];
        $logLength = (int)($details['length'] ?? 0);

        $teams = $details['teams'] ?? [];
        $redScore = (int)($teams['Red']['score'] ?? 0);
        $blueScore = (int)($teams['Blue']['score'] ?? 0);
        $wonIfRed = ($redScore > $blueScore) ? 1 : (($redScore < $blueScore) ? 0 : null);
        $wonIfBlue = ($blueScore > $redScore) ? 1 : (($blueScore < $redScore) ? 0 : null);

        foreach (($details['players'] ?? []) as $steamid => $pData) {
            $playerTeam = strtolower((string)($pData['team'] ?? ''));
            $won = ($playerTeam === 'red') ? $wonIfRed : (($playerTeam === 'blue') ? $wonIfBlue : null);

            $stats[$steamid] = [
                'team'               => $playerTeam,
                'length'             => $logLength,
                'won'                => $won,
                'dapm'               => (int)($pData['dapm'] ?? 0),
                'dmg'                => (int)($pData['dmg'] ?? 0),
                'dmg_taken'          => (int)($pData['dt'] ?? 0),
                'kills'              => (int)($pData['kills'] ?? 0),
                'deaths'             => (int)($pData['deaths'] ?? 0),
                'assists'            => (int)($pData['assists'] ?? 0),
                'suicides'           => (int)($pData['suicides'] ?? 0),
                'heal'               => (int)($pData['heal'] ?? 0),
                'medkits'            => (int)($pData['medkits'] ?? 0),
                'medkits_hp'         => (int)($pData['medkits_hp'] ?? 0),
                'ubers'              => (int)($pData['ubers'] ?? 0),
                'drops'              => (int)($pData['drops'] ?? 0),
                'backstabs'          => (int)($pData['backstabs'] ?? 0),
                'headshots'          => (int)($pData['headshots'] ?? 0),
                'airshots'           => (int)($pData['as'] ?? 0),
                'captures'           => (int)($pData['cpc'] ?? 0),
                'longest_killstreak' => (int)($pData['lks'] ?? 0),
                'classes_killed'     => json_encode($classKills[$steamid] ?? [], JSON_UNESCAPED_SLASHES),
            ];
        }

        return $stats;
    }
}
