<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ManagedTeamsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Import / ré-synchronisation d'une équipe ETF2L dans managed_teams.
 *
 * L'import crée la page équipe à partir des données de l'API ETF2L (noms,
 * tag, pays, division suggérée) et recopie le roster dans
 * managed_team_members (source = etf2l). La ré-synchronisation met à jour les
 * membres issus d'ETF2L sans jamais écraser les réglages manuels (classe,
 * statut, leader, description...) ni supprimer de membre.
 */
final class ManagedTeamImportService
{
    public function __construct(
        private readonly TeamStatsService $teamStats,
        private readonly ManagedTeamsRepository $teams,
    ) {}

    /**
     * Crée une équipe gérée à partir d'un id d'équipe ETF2L.
     *
     * @return array{ok: bool, error?: string, team?: array<string, mixed>}
     */
    public function import(int $etf2lTeamId): array
    {
        $ros = $this->teamStats->fetchRoster($etf2lTeamId);
        $meta = $this->teamStats->fetchTeamMeta($etf2lTeamId);

        if ($ros === null || $meta === null) {
            return ['ok' => false, 'error' => 'Équipe ETF2L introuvable (id '.$etf2lTeamId.').'];
        }

        $existing = DB::table('managed_teams')->where('etf2l_team_id', $etf2lTeamId)->first();
        if ($existing !== null) {
            return ['ok' => false, 'error' => 'Cette équipe est déjà gérée sur le site (id #'.$existing->id.').'];
        }

        $teamId = $this->teams->create([
            'slug' => $this->teams->uniqueSlug((string) $ros['name']),
            'name' => mb_substr((string) $ros['name'], 0, 255),
            'tag' => $ros['tag'] !== null && (string) $ros['tag'] !== '' ? mb_substr((string) $ros['tag'], 0, 64) : null,
            'country' => $ros['country'] !== null ? mb_substr((string) $ros['country'], 0, 64) : null,
            'etf2l_team_id' => $etf2lTeamId,
            'division' => $meta['suggested_division'] ?? null,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($ros['players'] as $player) {
            $this->teams->addMember($teamId, [
                'etf2l_player_id' => $player['player_id'],
                'steamid64' => $player['steamid64'],
                'steam_name' => $player['name'],
                'country' => $player['country'],
                'source' => 'etf2l',
            ]);
        }

        return [
            'ok' => true,
            'team' => $this->teams->find($teamId),
        ];
    }

    /**
     * Ré-synchronise le roster ETF2L d'une équipe gérée.
     *
     * Ne supprime jamais de membre et préserve les données manuelles (classe,
     * statut, leader) et les membres ajoutés à la main. Retourne un résumé.
     *
     * @return array{ok: bool, error?: string, created?: int, updated?: int, total?: int, departed?: int}
     */
    public function syncRoster(int $teamId): array
    {
        $team = $this->teams->find($teamId);
        if ($team === null) {
            return ['ok' => false, 'error' => 'Équipe inconnue.'];
        }

        $ros = $this->teamStats->fetchRoster((int) $team['etf2l_team_id']);
        if ($ros === null) {
            return ['ok' => false, 'error' => 'Roster ETF2L introuvable.'];
        }

        $current = DB::table('managed_team_members')
            ->where('team_id', $teamId)
            ->get()
            ->keyBy('steamid64');

        $created = 0;
        $updated = 0;

        foreach ($ros['players'] as $player) {
            $member = $current->get($player['steamid64']);

            if ($member === null) {
                $this->teams->addMember($teamId, [
                    'etf2l_player_id' => $player['player_id'],
                    'steamid64' => $player['steamid64'],
                    'steam_name' => $player['name'],
                    'country' => $player['country'],
                    'source' => 'etf2l',
                ]);
                $created++;

                continue;
            }

            $this->teams->updateMember($teamId, (int) $member->id, [
                'etf2l_player_id' => $player['player_id'],
                'steam_name' => $player['name'],
                'country' => $player['country'],
            ]);
            $updated++;
        }

        $departed = $current
            ->filter(static fn ($m): bool => ($m->source ?? 'manual') === 'etf2l')
            ->reject(static fn ($m): bool => isset($ros['players']) && in_array($m->steamid64, array_column($ros['players'], 'steamid64'), true))
            ->count();

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'total' => count($current) + $created,
            'departed' => $departed,
        ];
    }
}
