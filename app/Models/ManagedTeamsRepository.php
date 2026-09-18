<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\AvatarCache;
use App\Services\SteamId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Équipes françaises gérées (managed_teams) et leurs rosters
 * (managed_team_members) : accès métier via le query builder uniquement.
 *
 * Le roster du site est la source de vérité pour l'affichage : il est alimenté
 * par l'import ETF2L (source = etf2l) et complété à la main par l'admin ou le
 * team leader (source = manual).
 */
final class ManagedTeamsRepository
{
    /** Positions relatives des statuts de roster (affichage). */
    private const STATUS_ORDER = ['leader' => 0, 'starter' => 1, 'backup' => 2];

    /**
     * Équipes actives pour la sidebar et le listing, dans l'ordre des
     * divisions (Prem > High > Low > Open, puis alphabétique), avec le nombre
     * de membres.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeTeams(): array
    {
        $teams = DB::table('managed_teams as t')
            ->select('t.*')
            ->where('t.is_active', 1)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        $counts = $this->memberCounts(array_column($teams, 'id'));

        foreach ($teams as &$team) {
            $team['member_count'] = $counts[(int) $team['id']] ?? 0;
            $team['logo_url'] = self::logoUrl($team['logo_path'] ?? null);
        }
        unset($team);

        usort($teams, static function (array $a, array $b): int {
            $order = (array) config('hlfr.team_division_order', []);
            $oa = $order[$a['division'] ?? ''] ?? 99;
            $ob = $order[$b['division'] ?? ''] ?? 99;

            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $teams;
    }

    /**
     * Toutes les équipes (panel admin), avec nombre de membres et de leaders.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $teams = DB::table('managed_teams')
            ->orderBy('created_at')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        if ($teams === []) {
            return [];
        }

        $memberCounts = $this->memberCounts(array_column($teams, 'id'));
        $leaderCounts = DB::table('managed_team_members')
            ->whereIn('team_id', array_column($teams, 'id'))
            ->where('is_leader', 1)
            ->select('team_id', DB::raw('COUNT(*) as c'))
            ->groupBy('team_id')
            ->get()
            ->pluck('c', 'team_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        foreach ($teams as &$team) {
            $team['member_count'] = $memberCounts[(int) $team['id']] ?? 0;
            $team['leader_count'] = $leaderCounts[(int) $team['id']] ?? 0;
            $team['logo_url'] = self::logoUrl($team['logo_path'] ?? null);
        }
        unset($team);

        return $teams;
    }

    public function find(int $id): ?array
    {
        $row = DB::table('managed_teams')->where('id', $id)->first();

        return $row !== null ? (array) $row : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $row = DB::table('managed_teams')->where('slug', $slug)->first();

        return $row !== null ? (array) $row : null;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $query = DB::table('managed_teams')->where('slug', $slug);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return (bool) $query->exists();
    }

    /**
     * Génère un slug unique à partir d'un nom d'équipe.
     */
    public function uniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'equipe';
        }
        $base = mb_substr($base, 0, 100);

        $candidate = $base;
        $i = 2;
        while ($this->slugExists($candidate, $exceptId)) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): int
    {
        return (int) DB::table('managed_teams')->insertGetId($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): bool
    {
        return DB::table('managed_teams')->where('id', $id)->update($data) > 0;
    }

    public function delete(int $id): void
    {
        DB::table('managed_teams')->where('id', $id)->delete();
    }

    /**
     * Équipes françaises actives auxquelles appartient un joueur (profil),
     * dans l'ordre des divisions (Prem > High > Low > Open, puis alphabétique),
     * enrichies (lien équipe, logo, libellés de division, classe et statut).
     *
     * @return array<int, array<string, mixed>>
     */
    public function teamsForPlayer(string $steamid64): array
    {
        if (! preg_match('/^\d{17}$/', $steamid64)) {
            return [];
        }

        $rows = DB::table('managed_team_members as m')
            ->join('managed_teams as t', 't.id', '=', 'm.team_id')
            ->where('m.steamid64', $steamid64)
            ->where('t.is_active', 1)
            ->select(
                't.id',
                't.slug',
                't.name',
                't.tag',
                't.country',
                't.division',
                't.logo_path',
                'm.class',
                'm.status',
                'm.is_leader'
            )
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        if ($rows === []) {
            return [];
        }

        $divisions = (array) config('hlfr.team_divisions', []);
        $classLabels = (array) config('hlfr.tf2_classes', []);

        foreach ($rows as &$row) {
            $row['team_url'] = '/equipes/'.$row['slug'];
            $row['logo_url'] = self::logoUrl($row['logo_path'] ?? null);
            $row['division_label'] = $divisions[$row['division'] ?? ''] ?? null;
            $row['class_label'] = $classLabels[$row['class'] ?? ''] ?? null;
            $row['status_label'] = ($row['status'] ?? 'starter') === 'backup' ? 'Remplaçant' : 'Titulaire';
        }
        unset($row);

        usort($rows, static function (array $a, array $b): int {
            $order = (array) config('hlfr.team_division_order', []);
            $oa = $order[$a['division'] ?? ''] ?? 99;
            $ob = $order[$b['division'] ?? ''] ?? 99;

            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $rows;
    }

    /**
     * Membres d'une équipe, triés Leaders > Titulaires > Remplaçants puis
     * alphabétiquement, et enrichis (lien profil site, avatar, libellés).
     *
     * @return array<int, array<string, mixed>>
     */
    public function members(int $teamId): array
    {
        $members = DB::table('managed_team_members')
            ->where('team_id', $teamId)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        return $this->enrichMembers($members);
    }

    /**
     * Richit les membres : présence sur le site, lien profil, avatar, nom final,
     * libellés de classe et de statut.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return array<int, array<string, mixed>>
     */
    public function enrichMembers(array $members): array
    {
        $classLabels = (array) config('hlfr.tf2_classes', []);

        $siteMap = $this->sitePlayers(array_column($members, 'steamid64'));

        foreach ($members as &$member) {
            $steamid64 = (string) ($member['steamid64'] ?? '');
            $member['steamid3'] = $steamid64 !== '' ? SteamId::toSteamId3($steamid64) : null;
            $site = $siteMap[$steamid64] ?? null;

            $member['exists_on_site'] = $site !== null;
            $member['site_name'] = $site !== null ? ($site['display_name'] ?? '') !== '' ? (string) $site['display_name'] : (string) ($site['name'] ?? '') : '';
            $member['final_name'] = $member['site_name'] !== ''
                ? $member['site_name']
                : ((string) ($member['steam_name'] ?? '') !== '' ? (string) $member['steam_name'] : 'Joueur');
            $member['avatar_url'] = $site !== null
                ? AvatarCache::urlFor($steamid64)
                : '/_img/hf.webp';
            $member['profile_url'] = $site !== null ? '/profile/'.$steamid64 : null;
            $member['class_label'] = $classLabels[(string) ($member['class'] ?? '')] ?? null;
            $member['status_label'] = match ($member['status'] ?? 'starter') {
                'backup' => 'Remplaçant',
                default => 'Titulaire',
            };
        }
        unset($member);

        $order = self::STATUS_ORDER;
        usort($members, static function (array $a, array $b) use ($order): int {
            $sa = $order[($a['is_leader'] ?? false) ? 'leader' : ($a['status'] ?? 'starter')] ?? 9;
            $sb = $order[($b['is_leader'] ?? false) ? 'leader' : ($b['status'] ?? 'starter')] ?? 9;

            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            return strcasecmp((string) ($a['final_name'] ?? ''), (string) ($b['final_name'] ?? ''));
        });

        return $members;
    }

    /**
     * Ajoute un membre (manuel). Retourne l'id créé, ou null si le steamid64
     * fait déjà partie du roster.
     *
     * @param  array<string, mixed>  $data
     */
    public function addMember(int $teamId, array $data): ?int
    {
        $steamid64 = (string) ($data['steamid64'] ?? '');
        if (! preg_match('/^\d{17}$/', $steamid64)) {
            return null;
        }

        $exists = DB::table('managed_team_members')
            ->where('team_id', $teamId)
            ->where('steamid64', $steamid64)
            ->exists();

        if ($exists) {
            return null;
        }

        return (int) DB::table('managed_team_members')->insertGetId([
            'team_id' => $teamId,
            'steamid64' => $steamid64,
            'etf2l_player_id' => isset($data['etf2l_player_id']) ? (int) $data['etf2l_player_id'] : null,
            'steam_name' => isset($data['steam_name']) && $data['steam_name'] !== '' ? (string) $data['steam_name'] : null,
            'country' => isset($data['country']) && $data['country'] !== '' ? mb_substr((string) $data['country'], 0, 64) : null,
            'class' => isset($data['class']) && $data['class'] !== '' ? (string) $data['class'] : null,
            'status' => isset($data['status']) && in_array($data['status'], ['starter', 'backup'], true) ? (string) $data['status'] : 'starter',
            'source' => isset($data['source']) && in_array($data['source'], ['etf2l', 'manual'], true) ? (string) $data['source'] : 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMember(int $teamId, int $memberId, array $data): bool
    {
        $payload = [];

        if (array_key_exists('class', $data)) {
            $class = $data['class'] === null || $data['class'] === '' ? null : (string) $data['class'];
            $payload['class'] = $class;
        }
        if (array_key_exists('status', $data)) {
            $status = in_array((string) $data['status'], ['starter', 'backup'], true) ? (string) $data['status'] : 'starter';
            $payload['status'] = $status;
        }
        if (array_key_exists('steam_name', $data)) {
            $payload['steam_name'] = $data['steam_name'] !== null && (string) $data['steam_name'] !== ''
                ? mb_substr((string) $data['steam_name'], 0, 255)
                : null;
        }
        if (array_key_exists('country', $data)) {
            $payload['country'] = $data['country'] !== null && (string) $data['country'] !== ''
                ? mb_substr((string) $data['country'], 0, 64)
                : null;
        }
        if (array_key_exists('etf2l_player_id', $data)) {
            $payload['etf2l_player_id'] = $data['etf2l_player_id'] !== null ? (int) $data['etf2l_player_id'] : null;
        }
        if (array_key_exists('source', $data) && in_array((string) $data['source'], ['etf2l', 'manual'], true)) {
            $payload['source'] = (string) $data['source'];
        }
        if ($payload === []) {
            return true;
        }
        $payload['updated_at'] = now();

        return DB::table('managed_team_members')
            ->where('team_id', $teamId)
            ->where('id', $memberId)
            ->update($payload) > 0;
    }

    public function removeMember(int $teamId, int $memberId): bool
    {
        return DB::table('managed_team_members')
            ->where('team_id', $teamId)
            ->where('id', $memberId)
            ->delete() > 0;
    }

    /**
     * Promouvoir/destituer un leader (admin uniquement). Le leader doit être
     * membre du roster.
     */
    public function setLeader(int $teamId, int $memberId, bool $isLeader): bool
    {
        return DB::table('managed_team_members')
            ->where('team_id', $teamId)
            ->where('id', $memberId)
            ->update(['is_leader' => $isLeader ? 1 : 0, 'updated_at' => now()]) > 0;
    }

    /**
     * Le joueur connecté (steamid3) est-il leader de cette équipe ?
     */
    public function isTeamLeader(string $steamid3, int $teamId): bool
    {
        $steamid64 = SteamId::toSteamId64($steamid3);
        if ($steamid64 === null) {
            return false;
        }

        return (bool) DB::table('managed_team_members')
            ->where('team_id', $teamId)
            ->where('steamid64', $steamid64)
            ->where('is_leader', 1)
            ->exists();
    }

    /**
     * @param  array<int, int>  $teamIds
     * @return array<int, int> id équipe => nombre de membres
     */
    private function memberCounts(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        return DB::table('managed_team_members')
            ->whereIn('team_id', $teamIds)
            ->select('team_id', DB::raw('COUNT(*) as c'))
            ->groupBy('team_id')
            ->get()
            ->pluck('c', 'team_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * @param  string[]  $steamid64s
     * @return array<string, array<string, mixed>> steamid64 => joueur site
     */
    private function sitePlayers(array $steamid64s): array
    {
        $steamid64s = array_values(array_filter(array_unique($steamid64s), static fn (mixed $s): bool => is_string($s) && preg_match('/^\d{17}$/', $s) === 1));
        if ($steamid64s === []) {
            return [];
        }

        $steamid3s = array_map([SteamId::class, 'toSteamId3'], $steamid64s);

        $rows = DB::table('players_info')
            ->whereIn('steamid', $steamid3s)
            ->select('steamid', 'display_name', 'name')
            ->get()
            ->keyBy('steamid');

        $map = [];
        foreach ($steamid64s as $steamid64) {
            $steamid3 = SteamId::toSteamId3($steamid64);
            $row = $rows->get($steamid3);
            if ($row !== null) {
                $map[$steamid64] = (array) $row;
            }
        }

        return $map;
    }

    public static function logoUrl(?string $logoPath): ?string
    {
        if ($logoPath === null || $logoPath === '') {
            return null;
        }

        $logoPath = ltrim($logoPath, '/');
        if (preg_match('#^team-logos/(\d+)/([^/]+)$#', $logoPath, $matches) !== 1) {
            return null;
        }

        return url('/logo/team/'.$matches[1].'/'.rawurlencode($matches[2]));
    }

    /**
     * Parmi des ids de match ETF2L, ceux disposant d'une page locale
     * (/match/{id}) pour lier les derniers matchs d'une équipe.
     *
     * @param  int[]  $matchIds
     * @return array<int, int>
     */
    public function existingEtf2lMatchIds(array $matchIds): array
    {
        $matchIds = array_values(array_filter(array_map('intval', $matchIds), static fn (int $id): bool => $id > 0));
        if ($matchIds === []) {
            return [];
        }

        return DB::table('etf2l_matches')
            ->whereIn('match_id', $matchIds)
            ->pluck('match_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }
}
