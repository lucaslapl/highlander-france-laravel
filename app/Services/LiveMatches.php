<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Stockage de l'état des matchs en direct (serveurs de jeu -> site).
 *
 * Le plugin SourceMod hlfr_live_match POST régulièrement son état sur
 * /api/server/live-status ; ce service écrit un cache JSON et expose les
 * matchs "frais" via /api/live-matches.
 */
final class LiveMatches
{
    public const FILE = 'live_matches.json';

    /** Une entrée sans mise à jour depuis plus de ce délai est considérée hors-ligne. */
    public const TTL = 120;

    /**
     * Applique une mise à jour (statut "live" ou "ended") pour un serveur.
     *
     * Un "live" obsolète (updated_at antérieur au dernier seen pour ce serveur,
     * par ex. un heartbeat parti avant un "ended") est rejeté pour éviter de
     * réafficher un match terminé.
     */
    public static function apply(string $server, string $status, array $payload): bool
    {
        if ($server === '' || ! in_array($status, ['live', 'ended'], true)) {
            return false;
        }

        $data = self::read();
        $updatedAt = (int) ($payload['updated_at'] ?? time());
        $lastUpdated = $data['last_updated'] ?? [];
        $last = (int) ($lastUpdated[$server] ?? 0);

        if ($status === 'live' && $updatedAt < $last) {
            return false;
        }

        if ($status === 'ended') {
            unset($data['servers'][$server]);
        } else {
            $data['servers'][$server] = [
                'server' => $server,
                'map' => (string) ($payload['map'] ?? ''),
                'status' => 'live',
                'started_at' => (int) ($payload['started_at'] ?? time()),
                'updated_at' => $updatedAt,
                'scores' => [
                    'red' => max(0, (int) ($payload['scores']['red'] ?? 0)),
                    'blue' => max(0, (int) ($payload['scores']['blue'] ?? 0)),
                ],
                'players' => self::sanitizePlayers($payload['players'] ?? []),
                'stv' => self::sanitizeStv($payload['stv'] ?? null),
            ];
        }

        $lastUpdated[$server] = $updatedAt;

        // On borne la table des timestamps pour éviter qu'elle ne grossisse sans fin.
        $cutoff = time() - self::TTL * 2;
        foreach ($lastUpdated as $name => $ts) {
            if ((int) $ts < $cutoff) {
                unset($lastUpdated[$name]);
            }
        }
        $data['last_updated'] = $lastUpdated;

        return self::write($data);
    }

    /**
     * Liste des matchs en direct, sans les entrées périmées.
     *
     * @return array<string, array>
     */
    public static function all(): array
    {
        $data = self::read();
        $now = time();
        $servers = $data['servers'] ?? [];

        foreach ($servers as $name => $entry) {
            if (($entry['updated_at'] ?? 0) < $now - self::TTL) {
                unset($servers[$name]);
            }
        }

        return $servers;
    }

    public static function get(string $server): ?array
    {
        return self::all()[$server] ?? null;
    }

    /**
     * Enrichit les joueurs d'une entrée avec les infos du site (pseudo affiché,
     * avatar, lien profil) quand leur SteamID est connu en base.
     */
    public static function enrich(array $entry): array
    {
        $players = $entry['players'] ?? [];

        if ($players === []) {
            return $entry;
        }

        $steamid3s = [];

        foreach ($players as $p) {
            $steamid3 = isset($p['steamid']) ? self::steamId3((string) $p['steamid']) : null;
            if ($steamid3 !== null) {
                $steamid3s[] = $steamid3;
            }
        }

        $known = [];

        if ($steamid3s !== []) {
            $rows = DB::table('players_info')
                ->select('steamid', 'name', 'display_name', 'avatar')
                ->whereIn('steamid', $steamid3s)
                ->get();

            foreach ($rows as $row) {
                $known[$row->steamid] = [
                    'steamid' => $row->steamid,
                    'name' => $row->name,
                    'display_name' => $row->display_name,
                    'avatar' => $row->avatar,
                ];
            }
        }

        foreach ($players as &$p) {
            $steamid3 = isset($p['steamid']) ? self::steamId3((string) $p['steamid']) : null;

            if ($steamid3 !== null && isset($known[$steamid3])) {
                $row = $known[$steamid3];
                $p['display_name'] = $row['display_name'];
                $p['name'] = $row['name'];
                $p['avatar'] = $row['avatar'];
                $p['steamid64'] = SteamId::toSteamId64($steamid3);
            } else {
                $p['display_name'] = null;
                $p['avatar'] = null;
                $p['steamid64'] = null;
            }
        }
        unset($p);

        $entry['players'] = $players;

        return $entry;
    }

    private static function steamId3(string $steam2): ?string
    {
        $steamid64 = SteamId::fromSteam2($steam2);

        return $steamid64 !== null ? SteamId::toSteamId3($steamid64) : null;
    }

    /** @return array<string, mixed> */
    private static function read(): array
    {
        $file = hlfr_data_path(self::FILE);
        if (! is_file($file)) {
            return ['servers' => [], 'last_updated' => []];
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            return ['servers' => [], 'last_updated' => []];
        }

        $data['servers'] = $data['servers'] ?? [];
        $data['last_updated'] = $data['last_updated'] ?? [];

        return $data;
    }

    private static function write(array $data): bool
    {
        $fp = fopen(hlfr_data_path(self::FILE), 'c');

        if ($fp === false) {
            return false;
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        $written = fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $written !== false;
    }

    /** @return array<int, array<string, string|int>> */
    private static function sanitizePlayers(mixed $players): array
    {
        $result = [];

        if (! is_array($players)) {
            return $result;
        }

        foreach (array_slice($players, 0, 40) as $p) {
            if (! is_array($p)) {
                continue;
            }

            $team = (string) ($p['team'] ?? '');

            if (! in_array($team, ['red', 'blue'], true)) {
                continue;
            }

            $result[] = [
                'name' => mb_substr((string) ($p['name'] ?? ''), 0, 64),
                'team' => $team,
                'class' => mb_substr((string) ($p['class'] ?? ''), 0, 32),
                'steamid' => mb_substr((string) ($p['steamid'] ?? ''), 0, 32),
                'score' => max(0, (int) ($p['score'] ?? 0)),
            ];
        }

        return $result;
    }

    /** @return array<string, string|int>|null */
    private static function sanitizeStv(mixed $stv): ?array
    {
        if (! is_array($stv)) {
            return null;
        }

        $connect = (string) ($stv['connect'] ?? '');

        if ($connect === '') {
            return null;
        }

        $result = [
            'connect' => mb_substr($connect, 0, 512),
        ];

        if (isset($stv['ip'])) {
            $result['ip'] = mb_substr((string) $stv['ip'], 0, 64);
        }
        if (isset($stv['port'])) {
            $result['port'] = max(0, (int) $stv['port']);
        }
        if (isset($stv['password'])) {
            $result['password'] = mb_substr((string) $stv['password'], 0, 64);
        }

        return $result;
    }
}
