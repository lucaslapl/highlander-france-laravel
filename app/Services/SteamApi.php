<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Client de l'API Steam (profils joueurs).
 */
final class SteamApi
{
    private function key(): string
    {
        return (string) config('hlfr.steam_api_key', '');
    }

    private function request(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => config('hlfr.curl_verify_ssl'),
            CURLOPT_USERAGENT      => 'Highlander France Bot/1.0',
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);

            return null;
        }
        curl_close($ch);

        $data = json_decode((string) $response, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Met à jour le nom + l'avatar d'un joueur déjà présent en base (par SteamID3).
     * Utilisé par le dashboard pour rafraîchir le profil.
     */
    public function syncProfile(string $steamid3): bool
    {
        if ($this->key() === '') {
            return false;
        }

        $steamid64 = SteamId::toSteamId64($steamid3);
        if ($steamid64 === null) {
            return false;
        }

        $url = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=' . $this->key() . '&steamids=' . $steamid64;
        $data = $this->request($url);
        $player = $data['response']['players'][0] ?? null;

        if ($player === null) {
            return false;
        }

        DB::table('players_info')
            ->where('steamid', $steamid3)
            ->update([
                'name' => $player['personaname'],
                'avatar' => $player['avatarfull'],
                'last_updated' => time(),
            ]);

        return true;
    }

    /**
     * Met à jour le nom/l'avatar, et le display_name s'il est encore "Nouveau Joueur".
     * Utilisé après la connexion Steam (callback), comptes existants ou nouveaux.
     */
    public function syncOrCreatePlayer(string $steamid64): bool
    {
        if ($this->key() === '') {
            return false;
        }

        $url = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=' . $this->key() . '&steamids=' . $steamid64;
        $data = $this->request($url);
        $player = $data['response']['players'][0] ?? null;

        if ($player === null) {
            return false;
        }

        $steamName = $player['personaname'] ?? 'Joueur Steam';
        $steamAvatar = $player['avatarfull'] ?? '';
        $steamid3 = SteamId::toSteamId3($steamid64);

        DB::table('players_info')
            ->where('steamid', $steamid3)
            ->update([
                'name' => $steamName,
                'avatar' => $steamAvatar,
                // display_name remplacé uniquement s'il vaut encore "Nouveau Joueur".
                'display_name' => DB::raw(
                    "CASE WHEN display_name = 'Nouveau Joueur' OR display_name IS NULL OR display_name = '' "
                    . 'THEN ' . DB::getPdo()->quote($steamName) . ' ELSE display_name END'
                ),
            ]);

        return true;
    }
}
