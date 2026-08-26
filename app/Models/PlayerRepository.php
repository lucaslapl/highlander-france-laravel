<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SteamId;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PlayerRepository
{
    /**
     * Tous les membres ayant au moins un rôle actif dans le staff.
     *
     * @return array<int, array<string, mixed>>
     */
    public function staffMembers(): array
    {
        return DB::table('players_info')
            ->select('steamid', 'name', 'display_name', 'avatar', 'is_founder', 'is_mentor', 'is_mixer', 'is_moderator')
            ->where(function ($q): void {
                $q->where('is_founder', 1)
                    ->orWhere('is_mentor', 1)
                    ->orWhere('is_mixer', 1)
                    ->orWhere('is_moderator', 1);
            })
            ->orderBy('display_name')
            ->orderBy('name')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public function findById(string $steamid3): ?array
    {
        $player = DB::table('players_info')->where('steamid', $steamid3)->first();

        return $player !== null ? (array) $player : null;
    }

    public function findBySteamId64(string $steamid64): ?array
    {
        return $this->findById(SteamId::toSteamId3($steamid64));
    }

    /**
     * Recherche de joueurs par pseudo / pseudo d'affichage (Hall of Fame).
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array
    {
        return DB::table('players_info')
            ->select('steamid', 'name', 'display_name', 'avatar')
            ->where(function ($q) use ($query): void {
                $q->where('name', 'like', '%'.$query.'%')
                    ->orWhere('display_name', 'like', '%'.$query.'%');
            })
            ->orderBy('display_name')
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Ordre canonique des divisions par mode (0 = plus haut niveau), aligné sur
     * ComputePlayerLevelsService : les labels stockés en base sont exactement
     * ceux de ces échelles.
     */
    private const DIVISION_ORDER = [
        '9v9' => ['Premiership', 'High', 'Mid', 'Low', 'Open'],
        '6s' => ['Top Division', 'Division 1', 'Division 2', 'Division 3', 'Division 4', 'Low', 'Fresh'],
    ];

    /**
     * Page de joueurs inscrits (connectés au moins une fois : created_at
     * renseigné au login) avec leurs divisions 6s / HL.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function registeredPlayers(string $search, string $sort, string $dir, int $perPage, int $page): array
    {
        $query = $this->registeredPlayersQuery($search);

        $total = (clone $query)->count();

        $query = $this->sortRegisteredPlayers($query, $sort, $dir)
            ->skip(($page - 1) * $perPage)
            ->take($perPage);

        return [
            'rows' => $query->get()->map(static fn ($row): array => (array) $row)->all(),
            'total' => $total,
        ];
    }

    /**
     * Top 3 des classes les plus jouées pour un lot de joueurs (tous modes).
     *
     * @param  array<int, string>  $steamid3s
     * @return array<string, array<int, string>> steamid3 => classes triées
     */
    public function topClasses(array $steamid3s, int $limit = 3): array
    {
        if ($steamid3s === []) {
            return [];
        }

        $rows = DB::table('player_matches')
            ->select('steamid', 'class_played', DB::raw('COUNT(*) AS total'))
            ->whereIn('steamid', $steamid3s)
            ->whereNotNull('class_played')
            ->where('class_played', '!=', '')
            ->groupBy('steamid', 'class_played')
            ->get();

        $byPlayer = [];
        foreach ($rows as $row) {
            $byPlayer[$row->steamid][$row->class_played] = (int) $row->total;
        }

        $result = [];
        foreach ($byPlayer as $steamid => $classes) {
            arsort($classes);
            $result[$steamid] = array_slice(array_keys($classes), 0, $limit);
        }

        return $result;
    }

    /**
     * Requête de base des joueurs inscrits (filtre recherche inclus).
     */
    private function registeredPlayersQuery(string $search): Builder
    {
        $query = DB::table('players_info as pi')
            ->leftJoin('player_levels as hl', static function ($join): void {
                $join->on('hl.steamid', '=', 'pi.steamid')->where('hl.game_mode', '9v9');
            })
            ->leftJoin('player_levels as six', static function ($join): void {
                $join->on('six.steamid', '=', 'pi.steamid')->where('six.game_mode', '6s');
            })
            ->whereNotNull('pi.created_at')
            ->select(
                'pi.steamid',
                'pi.name',
                'pi.display_name',
                'pi.avatar',
                'pi.country',
                'hl.division_label AS hl_division',
                'six.division_label AS div6_division',
            );

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('pi.name', 'like', '%'.$search.'%')
                    ->orWhere('pi.display_name', 'like', '%'.$search.'%');
            });
        }

        return $query;
    }

    /**
     * Tri de la liste : alphabétique, division HL ou division 6s. Le rang est
     * calculé via CASE sur l'échelle canonique (les joueurs sans division
     * connue passent en fin de liste quel que soit le sens du tri).
     */
    private function sortRegisteredPlayers(Builder $query, string $sort, string $dir): Builder
    {
        $direction = strtolower($dir) === 'desc' ? 'desc' : 'asc';

        if ($sort === 'name') {
            return $query->orderBy('pi.display_name', $direction)
                ->orderBy('pi.name', $direction);
        }

        $column = $sort === 'div6' ? 'six' : 'hl';
        $cases = [];
        foreach (self::DIVISION_ORDER[$sort === 'div6' ? '6s' : '9v9'] as $rank => $label) {
            $cases[] = "when {$column}.division_label = '".addslashes($label)."' then {$rank}";
        }
        // Sans division => dernier rang ; sens inversé pour rester en queue.
        $fallback = $direction === 'asc'
            ? count(self::DIVISION_ORDER[$sort === 'div6' ? '6s' : '9v9'])
            : -1;

        return $query->orderByRaw(
            'CASE '.implode(' ', $cases).' ELSE '.$fallback.' END '.$direction
        )->orderBy('pi.display_name')
            ->orderBy('pi.name');
    }

    /**
     * Tous les SteamID (format steamid3) des joueurs indexés, pour le sitemap.
     *
     * @return array<int, string>
     */
    public function allSteamIds(): array
    {
        return DB::table('players_info')->orderBy('steamid')->pluck('steamid')->all();
    }

    /**
     * Insère le joueur s'il n'existe pas (idempotent).
     */
    public function createIfMissing(string $steamid3): void
    {
        $exists = DB::table('players_info')->where('steamid', $steamid3)->exists();

        if (! $exists) {
            DB::table('players_info')->insert([
                'steamid' => $steamid3,
                'display_name' => 'Nouveau Joueur',
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Renseigne created_at si vide (première connexion d'un compte ancien).
     *
     * NB : on ne teste que IS NULL — l'ancien code SQLite comparait aussi à ''
     * mais MySQL (mode strict) refuse la comparaison d'un DATETIME avec ''.
     */
    public function ensureCreatedAt(string $steamid3): void
    {
        DB::table('players_info')
            ->where('steamid', $steamid3)
            ->whereNull('created_at')
            ->update(['created_at' => now()]);
    }

    public function hasNameChanged(string $steamid3): bool
    {
        $value = DB::table('players_info')->where('steamid', $steamid3)->value('name_changed');

        return (int) $value === 1;
    }

    /**
     * Enregistre le pseudo d'affichage (unique et définitif).
     */
    public function updateDisplayName(string $steamid3, string $name): bool
    {
        if ($this->hasNameChanged($steamid3)) {
            return false;
        }

        DB::table('players_info')
            ->where('steamid', $steamid3)
            ->update(['display_name' => $name, 'name_changed' => 1]);

        return true;
    }

    /**
     * Enregistre les liens externes du profil (facultatifs, modifiables à volonté).
     * Les clés du tableau doivent correspondre aux colonnes de players_info.
     *
     * @param  array<string, string|null>  $links
     */
    public function updateProfileLinks(string $steamid3, array $links): bool
    {
        $allowed = array_keys(config('hlfr.profile_links'));
        $data = array_intersect_key($links, array_flip($allowed));

        if ($data === []) {
            return false;
        }

        return DB::table('players_info')->where('steamid', $steamid3)->update($data) >= 0;
    }

    /**
     * Enregistre la date de naissance et le matériel (facultatifs, modifiables à volonté).
     *
     * @param  array<string, string|null>  $gear
     */
    public function updatePersonalInfo(string $steamid3, ?string $birthdate, array $gear): bool
    {
        $allowed = array_keys(config('hlfr.profile_gear'));
        $data = array_intersect_key($gear, array_flip($allowed));
        $data['birthdate'] = $birthdate;

        return DB::table('players_info')->where('steamid', $steamid3)->update($data) >= 0;
    }

    public function hasCountryLocked(string $steamid3): bool
    {
        $value = DB::table('players_info')->where('steamid', $steamid3)->value('country_locked');

        return (int) $value === 1;
    }

    /**
     * Enregistre la nationalité (unique et définitive).
     */
    public function updateCountry(string $steamid3, string $country): bool
    {
        if ($this->hasCountryLocked($steamid3)) {
            return false;
        }

        DB::table('players_info')
            ->where('steamid', $steamid3)
            ->update(['country' => $country, 'country_locked' => 1]);

        return true;
    }
}
