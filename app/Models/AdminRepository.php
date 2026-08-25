<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SteamId;
use Illuminate\Support\Facades\DB;

/**
 * Données et actions du panel d'administration.
 */
final class AdminRepository
{
    /**
     * Indicateurs + séries pour les graphiques du dashboard.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return [
            'totalPlayers' => (int) DB::table('players_info')->count(),
            'totalRegistered' => (int) DB::table('players_info')->whereNotNull('created_at')->count(),
            'totalStaff' => (int) DB::table('players_info')
                ->where(function ($q): void {
                    $q->where('is_admin', 1)
                        ->orWhere('is_founder', 1)
                        ->orWhere('is_moderator', 1)
                        ->orWhere('is_mentor', 1)
                        ->orWhere('is_mixer', 1);
                })
                ->count(),
            'registrations' => $this->registrations(),
            'matchesPerDay' => $this->matchesPerDay(),
            'modes' => $this->modes(),
            'recentUsers' => $this->recentUsers(),
        ];
    }

    /**
     * @return array<int, array{d: string, nb: int}>
     */
    public function registrations(): array
    {
        $rows = DB::table('players_info')
            ->whereNotNull('created_at')
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderByRaw('DATE(created_at) ASC')
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS nb')
            ->get();

        $rowsOut = [];
        foreach ($rows as $row) {
            $rowsOut[] = ['d' => $row->d, 'nb' => (int) $row->nb];
        }

        return $rowsOut;
    }

    /**
     * @return array<int, array{d: string, nb: int}>
     */
    public function matchesPerDay(): array
    {
        $rows = DB::table('player_matches as pm')
            ->join('log_dates as ld', 'ld.log_id', '=', 'pm.match_id')
            ->whereNotNull('ld.date')
            ->where('ld.date', '>=', strtotime('-12 months'))
            ->groupBy(DB::raw("DATE(FROM_UNIXTIME(ld.date))"))
            ->orderBy(DB::raw('DATE(FROM_UNIXTIME(ld.date))'))
            ->selectRaw("DATE(FROM_UNIXTIME(ld.date)) AS d, COUNT(DISTINCT pm.match_id) AS nb")
            ->get();

        $rowsOut = [];
        foreach ($rows as $row) {
            $rowsOut[] = ['d' => $row->d, 'nb' => (int) $row->nb];
        }

        return $rowsOut;
    }

    /**
     * Nombre de matchs distincts par mode.
     *
     * @return array<string, int>
     */
    public function modes(): array
    {
        $modes = [];
        foreach (DB::table('player_matches')->select('game_mode', DB::raw('COUNT(DISTINCT match_id) AS nb'))->groupBy('game_mode')->get() as $row) {
            $modes[$row->game_mode] = (int) $row->nb;
        }

        return $modes;
    }

    /**
     * 5 derniers inscrits (steamid3 + steamid64 pour l'affichage).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentUsers(): array
    {
        $users = [];
        foreach (DB::table('players_info')->orderByDesc('created_at')->limit(5)->get() as $user) {
            $users[] = [
                'steamid' => $user->steamid,
                'steamid64' => SteamId::toSteamId64($user->steamid),
                'name' => $user->name,
                'display_name' => $user->display_name,
                'created_at' => $user->created_at,
            ];
        }

        return $users;
    }

    /**
     * Admins (équipe technique).
     *
     * @return array<int, array<string, mixed>>
     */
    public function technicalTeam(): array
    {
        return DB::table('players_info')
            ->select('steamid', 'display_name', 'country')
            ->where('is_admin', 1)
            ->orderBy('display_name')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Liste complète de la blacklist (id, raison, auteur, date).
     *
     * @return array<int, array<string, mixed>>
     */
    public function blacklist(): array
    {
        return DB::table('log_blacklist')
            ->orderByDesc('created_at')
            ->orderByDesc('log_id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Résout le pseudo d'un admin à partir du SteamID64 stocké en base.
     * Les valeurs non-SteamID ('legacy', 'auto', 'Inconnu') sont retournées telles quelles.
     */
    public function adminDisplayName(string $addedBy): string
    {
        if ($addedBy === '' || ! preg_match('/^\d{17}$/', $addedBy)) {
            return $addedBy;
        }

        $p = DB::table('players_info')
            ->where('steamid', SteamId::toSteamId3($addedBy))
            ->first();

        if ($p !== null) {
            return !empty($p->display_name) ? $p->display_name : $p->name;
        }

        return $addedBy;
    }

    /**
     * Mode (6s/9v9) de chaque log traité en base.
     *
     * @return array<int, string>
     */
    public function dbModes(): array
    {
        $modes = [];
        foreach (
            DB::table('player_matches')
                ->select('match_id', DB::raw('MIN(game_mode) AS game_mode'))
                ->groupBy('match_id')
                ->get() as $row
        ) {
            $modes[(int) $row->match_id] = $row->game_mode;
        }

        return $modes;
    }

    /**
     * Durées en cache (log_length_cache).
     *
     * @return array<int, int>
     */
    public function logLengths(): array
    {
        $lengths = [];
        foreach (DB::table('log_length_cache')->get() as $row) {
            $lengths[(int) $row->log_id] = (int) $row->length;
        }

        return $lengths;
    }

    /**
     * @param array<int, int> $lengths
     */
    public function saveLogLengths(array $lengths): void
    {
        foreach ($lengths as $id => $length) {
            DB::table('log_length_cache')->insertOrIgnore(['log_id' => $id, 'length' => $length]);
        }
    }

    /**
     * Mise à jour globale du profil d'un joueur (pseudo, pays, rôles, verrous).
     */
    public function updatePlayer(
        string $steamid3,
        string $displayName,
        string $country,
        int $isFounder,
        int $isModerator,
        int $isMentor,
        int $isMixer,
        bool $resetNameChange,
        bool $resetCountryChange,
    ): bool {
        $values = [
            'display_name' => $displayName,
            'country' => $country,
            'is_founder' => $isFounder,
            'is_moderator' => $isModerator,
            'is_mentor' => $isMentor,
            'is_mixer' => $isMixer,
        ];

        if ($resetNameChange) {
            $values['name_changed'] = 0;
        }
        if ($resetCountryChange) {
            $values['country_locked'] = 0;
        }

        return DB::table('players_info')->where('steamid', $steamid3)->update($values) >= 0;
    }

    /**
     * Bascule le mode (6s/9v9) d'un log et ajuste les compteurs joueurs.
     *
     * @return array{success: bool, message: string}
     */
    public function switchMatchMode(int $logId, string $mode): array
    {
        $current = DB::table('player_matches')->where('match_id', $logId)->value('game_mode');

        if ($current === null) {
            return ['success' => false, 'message' => "Ce log n'est pas encore traité en base de données (aucun joueur associé)."];
        }
        if ($current === $mode) {
            return ['success' => false, 'message' => "Le log #$logId est déjà en mode $mode."];
        }

        $steamids = DB::table('player_matches')->where('match_id', $logId)->pluck('steamid');

        try {
            DB::beginTransaction();

            DB::table('player_matches')->where('match_id', $logId)->update(['game_mode' => $mode]);

            foreach ($steamids as $steamid) {
                DB::table('player_stats')
                    ->where('steamid', $steamid)
                    ->where('game_mode', $current)
                    ->decrement('count');
            }
            DB::table('player_stats')->where('count', '<=', 0)->delete();

            // Ré-incrémente le nouveau mode pour chaque joueur du log.
            foreach ($steamids as $steamid) {
                DB::table('player_stats')->upsert(
                    ['steamid' => $steamid, 'count' => 1, 'game_mode' => $mode],
                    ['steamid', 'game_mode'],
                    [DB::raw('count = count + 1')],
                );
            }

            DB::commit();

            return ['success' => true, 'message' => "Le log #$logId est passé du mode $current au mode $mode."];
        } catch (\Throwable $e) {
            DB::rollBack();

            return ['success' => false, 'message' => 'Erreur BDD : ' . $e->getMessage()];
        }
    }
}
