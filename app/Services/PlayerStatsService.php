<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Calcul de stats joueur à partir de logs logs.tf saisis manuellement
 * (IDs ou URLs), pour la page admin « Stats joueur ».
 *
 * Indépendante de la base : chaque log est récupéré à la volée via
 * logs.tf/api/v1/log/{id} puis parsé avec LogParser. Le fetch passe par une
 * closure injectable pour permettre les tests sans réseau.
 */
final class PlayerStatsService
{
    /** Délai minimal entre deux appels HTTP (bonne citoyenneté logs.tf). */
    private const HTTP_DELAY_S = 1.1;

    /** Libellés des stats calculables (clé => intitulé affiché). */
    public const STAT_LABELS = [
        'kd' => 'Ratio K/D',
        'dpm' => 'DPM moyen',
        'dmg' => 'Dégâts (total + moyenne)',
        'heal' => 'Soins reçus',
        'winrate' => 'Winrate',
    ];

    private float $lastHttpAt = 0.0;

    /**
     * @param  \Closure(int): (array<string, mixed>|null)  $fetcher  Récupère le détail d'un log logs.tf
     */
    public function __construct(private readonly ?\Closure $fetcher = null) {}

    public function stats(): array
    {
        return array_keys(self::STAT_LABELS);
    }

    /**
     * Résout la saisie (SteamID64, SteamID3, SteamID2, URL steamcommunity)
     * vers un SteamID3, clé utilisée par l'API logs.tf.
     */
    public function resolvePlayer(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (preg_match('#steamcommunity\.com/profiles/(\d{17})#i', $input, $m)) {
            return SteamId::toSteamId3($m[1]);
        }

        if (preg_match('/^\d{17}$/', $input)) {
            return SteamId::toSteamId3($input);
        }

        if (preg_match('/^STEAM_[0-1]:([0-1]):(\d+)$/i', $input, $m)) {
            $steamid64 = SteamId::fromSteam2(strtoupper($input));

            return $steamid64 !== null ? SteamId::toSteamId3($steamid64) : null;
        }

        if (preg_match('/\[?U:1:(\d+)\]?/', $input, $m)) {
            return '[U:1:'.$m[1].']';
        }

        return null;
    }

    /**
     * Résout un input logs.tf (ID brut ou URL logs.tf/<id>) vers un ID.
     */
    public function parseLogId(string $input): ?int
    {
        $input = trim($input);

        if (preg_match('#logs\.tf/(\d{4,10})#i', $input, $m)) {
            return (int) $m[1];
        }

        if (preg_match('#(?:\/|^)(\d{4,10})\s*$#', $input, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Récupère et agrège les stats d'un joueur sur une liste de logs.
     *
     * @param  int[]  $logIds
     * @param  string[]  $stats
     * @return array<string, mixed>
     */
    public function compute(string $steamId3, array $logIds, array $stats, ?callable $progress = null): array
    {
        $logIds = array_values(array_unique(array_filter(array_map('intval', $logIds), static fn (int $id): bool => $id > 0)));
        $selected = array_intersect($this->stats(), $stats);
        $total = count($logIds);
        $fetcher = $this->fetcher ?? static fn (int $logId): ?array => JsonClient::get('https://logs.tf/api/v1/log/'.$logId);

        $logs = [];
        $acc = ['kills' => 0, 'deaths' => 0, 'dmg' => 0, 'heal' => 0, 'dpmSum' => 0, 'dpmCount' => 0, 'wins' => 0, 'decided' => 0];
        $usable = 0;

        foreach ($logIds as $i => $logId) {
            if ($progress !== null) {
                $progress($i, $total, 'Récupération du log '.$logId.'…');
            }

            $this->throttle();

            $entry = ['log_id' => $logId, 'found' => false, 'player_present' => false, 'error' => null];

            try {
                $details = $fetcher($logId);
            } catch (\Throwable $e) {
                $details = null;
                $entry['error'] = $e->getMessage();
            }

            if (is_array($details) && isset($details['players'])) {
                $entry['found'] = true;
                $parsed = LogParser::extract($details);

                if (isset($parsed[$steamId3])) {
                    $p = $parsed[$steamId3];
                    $length = (int) ($p['length'] ?? 0);
                    $won = $p['won'] ?? null;

                    $entry['player_present'] = true;
                    $entry['length'] = $length;
                    $entry['dmg'] = (int) $p['dmg'];
                    $entry['kills'] = (int) $p['kills'];
                    $entry['deaths'] = (int) $p['deaths'];
                    $entry['dapm'] = (int) $p['dapm'];
                    $entry['heal'] = (int) $p['heal'];
                    $entry['won'] = $won;

                    $acc['kills'] += (int) $p['kills'];
                    $acc['deaths'] += (int) $p['deaths'];
                    $acc['dmg'] += (int) $p['dmg'];
                    $acc['heal'] += (int) $p['heal'];

                    if ($length > 0) {
                        $acc['dpmSum'] += (int) $p['dapm'];
                        $acc['dpmCount']++;
                    }

                    if ($won !== null) {
                        $acc['decided']++;
                        if ((int) $won === 1) {
                            $acc['wins']++;
                        }
                    }

                    $usable++;
                }
            } elseif (is_array($details)) {
                $entry['error'] = 'Log introuvable sur logs.tf (ID '.$logId.').';
            } else {
                $entry['error'] = 'Impossible de récupérer le log '.$logId.' (API injoignable).';
            }

            $logs[] = $entry;

            if ($progress !== null) {
                $progress($i + 1, $total, 'Log '.$logId.' traité.');
            }
        }

        $kd = $acc['deaths'] > 0
            ? round($acc['kills'] / $acc['deaths'], 2)
            : ($acc['kills'] > 0 ? (float) $acc['kills'] : 0.0);
        $dpm = $acc['dpmCount'] > 0 ? (int) round($acc['dpmSum'] / $acc['dpmCount'], 0) : null;
        $dmgAvg = $usable > 0 ? (int) round($acc['dmg'] / $usable, 0) : null;
        $winrate = $acc['decided'] > 0 ? (int) round($acc['wins'] * 100 / $acc['decided'], 0) : null;

        $statsOut = [];
        if (in_array('kd', $selected, true)) {
            $statsOut['kd'] = $kd;
        }
        if (in_array('dpm', $selected, true)) {
            $statsOut['dpm'] = $dpm;
        }
        if (in_array('dmg', $selected, true)) {
            $statsOut['dmg_total'] = $acc['dmg'];
            $statsOut['dmg_avg'] = $dmgAvg;
        }
        if (in_array('heal', $selected, true)) {
            $statsOut['heal'] = $acc['heal'];
        }
        if (in_array('winrate', $selected, true)) {
            $statsOut['winrate'] = $winrate;
        }

        return [
            'player' => ['steamid' => $steamId3, 'steamid64' => SteamId::toSteamId64($steamId3)],
            'logs' => $logs,
            'stats' => $statsOut,
            'logs_total' => $total,
            'logs_usable' => $usable,
        ];
    }

    private function throttle(): void
    {
        $elapsed = microtime(true) - $this->lastHttpAt;
        if ($this->lastHttpAt > 0 && $elapsed < self::HTTP_DELAY_S) {
            usleep((int) ((self::HTTP_DELAY_S - $elapsed) * 1e6));
        }
        $this->lastHttpAt = microtime(true);
    }
}
