<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class FranceBadgeService
{
    public static function forSteamId3(string $steamid3): array
    {
        $rows = DB::table('france_national_players')->where('steamid', $steamid3)->get();

        $modes = [];
        foreach ($rows as $row) {
            $modes[(string) $row->mode] = true;
        }

        return [
            '6v6' => !empty($modes['6v6']),
            'highlander' => !empty($modes['highlander']),
        ];
    }

    public static function forSteamId64(string $steamid64): array
    {
        return self::forSteamId3(SteamId::toSteamId3($steamid64));
    }

    public static function bulkForSteamId3(array $steamid3s): array
    {
        if ($steamid3s === []) {
            return [];
        }

        $rows = DB::table('france_national_players')->whereIn('steamid', $steamid3s)->get();

        $map = [];
        foreach ($steamid3s as $id) {
            $map[$id] = ['6v6' => false, 'highlander' => false];
        }

        foreach ($rows as $row) {
            $sid = (string) $row->steamid;
            $mode = (string) $row->mode;
            if (isset($map[$sid]) && ($mode === '6v6' || $mode === 'highlander')) {
                $map[$sid][$mode] = true;
            }
        }

        return $map;
    }

    public static function bulkForSteamId64(array $steamid64s): array
    {
        if ($steamid64s === []) {
            return [];
        }

        $map64 = [];
        $to3 = [];
        foreach ($steamid64s as $id64) {
            $id3 = SteamId::toSteamId3($id64);
            $to3[$id64] = $id3;
        }

        $bulk = self::bulkForSteamId3(array_values($to3));

        foreach ($to3 as $id64 => $id3) {
            $map64[$id64] = $bulk[$id3] ?? ['6v6' => false, 'highlander' => false];
        }

        return $map64;
    }
}
