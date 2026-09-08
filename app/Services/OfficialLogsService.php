<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MatchStatsRepository;
use App\Models\OfficialLogsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Récupération et rattachement des logs.tf officiels de ligue.
 *
 * - Extraction des liens logs.tf depuis la page HTML d'un match ETF2L
 *   (https://etf2l.org/matches/{id}/) : l'API v2 ne les expose pas.
 * - Attribution Red/Blue -> équipe ETF2L par règle de majorité sur les rosters
 *   (fallback : comparaison des noms d'équipe du log).
 */
final class OfficialLogsService
{
    private float $lastHttpAt = 0.0;

    /**
     * Catégorie depuis le nom de compétition ETF2L.
     */
    public function categoryForCompetition(?string $competitionName): string
    {
        $name = strtolower((string) $competitionName);

        return str_contains($name, '6v6') || str_contains($name, '6s') ? '6s' : '9v9';
    }

    /**
     * IDs logs.tf présents dans le HTML d'une page de match ETF2L.
     *
     * @return int[]
     */
    public function extractLogIdsFromMatchPage(string $html): array
    {
        $ids = [];
        if (preg_match_all('#logs\.tf/(\d+)#i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Maille une étape du débit HTTP vers ETF2L (bonne citoyenneté).
     */
    private function throttle(): void
    {
        $elapsed = microtime(true) - $this->lastHttpAt;
        if ($this->lastHttpAt > 0 && $elapsed < 1.1) {
            usleep((int) ((1.1 - $elapsed) * 1e6));
        }
        $this->lastHttpAt = microtime(true);
    }

    /**
     * Scrape une page de match ETF2L et rattache tous les logs trouvés.
     *
     * @return array{logs: int[], attached: int, skipped: array<int, string>}
     */
    public function scrapeMatch(int $matchId): array
    {
        $match = DB::table('etf2l_matches')->where('match_id', $matchId)->first();

        $empty = ['logs' => [], 'attached' => 0, 'skipped' => []];

        if ($match === null) {
            return $empty;
        }

        $this->throttle();
        $raw = JsonClient::getRaw(
            'https://etf2l.org/matches/'.$matchId.'/',
            20,
            'Highlander France Bot/1.0',
        );

        if ($raw['body'] === null || $raw['http_code'] >= 500) {
            // Erreur transitoire : on ne marque pas la page comme vérifiée.
            return $empty;
        }

        $logIds = $this->extractLogIdsFromMatchPage($raw['body']);

        $now = time();
        DB::table('etf2l_matches')->where('match_id', $matchId)->update(['logs_checked_at' => $now]);

        if ($logIds === []) {
            return $empty;
        }

        return $this->attachForMatch($matchId, $logIds, 'auto', null);
    }

    /**
     * Rattache une liste de logs à un match ETF2L (auto ou manuel).
     *
     * @param  int[]  $logIds
     * @return array{logs: int[], attached: int, skipped: array<int, string>}
     */
    public function attachForMatch(int $matchId, array $logIds, string $source, ?string $addedBy): array
    {
        $match = DB::table('etf2l_matches')->where('match_id', $matchId)->first();
        $result = ['logs' => [], 'attached' => 0, 'skipped' => []];
        $repo = new OfficialLogsRepository;

        if ($match === null) {
            return $result;
        }

        $category = $this->categoryForCompetition((string) ($match->competition_name ?? ''));
        $team1Id = (int) ($match->team1_id ?? 0);
        $team2Id = (int) ($match->team2_id ?? 0);
        $matchDate = (int) ($match->match_date ?? 0);

        // Équipes blacklistées : on ne rattache rien (leurs logs polluent les stats).
        $blacklistedTeams = DB::table('team_blacklist')->pluck('team_id')->map('intval')->all();
        if ($team1Id > 0 && in_array($team1Id, $blacklistedTeams, true)) {
            return $result;
        }
        if ($team2Id > 0 && in_array($team2Id, $blacklistedTeams, true)) {
            return $result;
        }

        foreach ($logIds as $logId) {
            $logId = (int) $logId;
            $result['logs'][] = $logId;

            if ($repo->exists($logId)) {
                $result['skipped'][$logId] = 'déjà rattaché';

                continue;
            }

            [$redTeamId, $blueTeamId] = $this->resolveSideAttribution($logId, $team1Id, $team2Id);

            $repo->attach(
                $logId,
                $category,
                $matchId,
                null,
                $redTeamId,
                $blueTeamId,
                $source,
                $addedBy,
            );
            $result['attached']++;

            // Date du match comme date du log (fallback : date réelle du log).
            $date = $this->logDate($logId);
            if ($date === null && $matchDate > 0) {
                (new MatchStatsRepository)->saveLogDate($logId, $matchDate);
            }
        }

        return $result;
    }

    /**
     * Rattache un log à une seule équipe (matchs hors ETF2L, ex. équipe de France).
     */
    public function attachTeamLog(int $logId, int $teamId, string $category, ?string $addedBy): bool
    {
        if (DB::table('team_blacklist')->where('team_id', $teamId)->exists()) {
            return false;
        }

        $repo = new OfficialLogsRepository;

        $details = $this->fetchLogDetail($logId);
        if ($details === null) {
            return false;
        }

        // Le camp dans lequel l'équipe a joué (règle de majorité sur son roster).
        $redCount = $this->sideRosterMatches($details, 'red', [$teamId]);
        $blueCount = $this->sideRosterMatches($details, 'blue', [$teamId]);

        $redTeamId = $blueCount >= $redCount ? null : $teamId;
        $blueTeamId = $redCount >= $blueCount ? null : $teamId;
        if (max($redCount, $blueCount) === 0) {
            // Aucun membre du roster trouvé : on rattache quand même au scope.
            $redTeamId = null;
            $blueTeamId = null;
        }

        $repo->attach($logId, $category, null, $teamId, $redTeamId, $blueTeamId, 'manual', $addedBy);

        $date = $this->logDate($logId);
        if ($date !== null) {
            (new MatchStatsRepository)->saveLogDate($logId, $date);
        }

        return true;
    }

    /**
     * Attribution Red/Blue aux deux équipes ETF2L d'un match, par règle de
     * majorité sur les rosters. Retourne [redTeamId, blueTeamId] (0 = indéterminé).
     *
     * @return array{0: int, 1: int}
     */
    public function resolveSideAttribution(int $logId, int $team1Id, int $team2Id): array
    {
        $details = $this->fetchLogDetail($logId);
        $team1Id = max(0, $team1Id);
        $team2Id = max(0, $team2Id);

        if ($details === null) {
            return [$team1Id, $team2Id];
        }

        $roster1 = $team1Id > 0 ? $this->rosterSteamids($team1Id) : [];
        $roster2 = $team2Id > 0 ? $this->rosterSteamids($team2Id) : [];
        $redRoster = $roster1 !== [] || $roster2 !== [] ? $roster1 : null;

        $redBelongs1 = $this->sideRosterCount($details, 'red', $roster1);
        $redBelongs2 = $this->sideRosterCount($details, 'red', $roster2);
        $blueBelongs1 = $this->sideRosterCount($details, 'blue', $roster1);
        $blueBelongs2 = $this->sideRosterCount($details, 'blue', $roster2);

        $redTeamId = 0;
        $blueTeamId = 0;
        if ($redBelongs1 + $redBelongs2 > 0 || $blueBelongs1 + $blueBelongs2 > 0) {
            $redTeamId = $redBelongs1 >= $redBelongs2 ? $team1Id : $team2Id;
            $blueTeamId = $redTeamId === $team1Id ? $team2Id : $team1Id;
        } else {
            // Fallback : correspondance des noms d'équipe du log.
            $redName = strtolower(trim((string) ($details['teams']['Red']['name'] ?? '')));
            $blueName = strtolower(trim((string) ($details['teams']['Blue']['name'] ?? '')));
            $name1 = $this->matchName($team1Id);
            $name2 = $this->matchName($team2Id);

            if ($this->namesMatch($redName, $name1)) {
                $redTeamId = $team1Id;
                $blueTeamId = $team2Id;
            } elseif ($this->namesMatch($redName, $name2)) {
                $redTeamId = $team2Id;
                $blueTeamId = $team1Id;
            } elseif ($this->namesMatch($blueName, $name1)) {
                $blueTeamId = $team1Id;
                $redTeamId = $team2Id;
            } elseif ($this->namesMatch($blueName, $name2)) {
                $blueTeamId = $team2Id;
                $redTeamId = $team1Id;
            } else {
                $redTeamId = $team1Id;
                $blueTeamId = $team2Id;
            }
        }

        return [$redTeamId, $blueTeamId];
    }

    /**
     * Nombre de membres du roster (ou des rosters) présents dans un camp du log.
     *
     * @param  array<string, mixed>  $details
     * @param  int[]  $roster
     */
    private function sideRosterCount(array $details, string $side, array $roster): int
    {
        if ($roster === []) {
            return 0;
        }

        $count = 0;
        foreach (($details['players'] ?? []) as $steamid => $p) {
            if (strtolower((string) ($p['team'] ?? '')) !== $side) {
                continue;
            }
            if (isset($roster[(string) $steamid])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  int[]  $teamIds
     */
    private function sideRosterMatches(array $details, string $side, array $teamIds): int
    {
        $count = 0;
        foreach ($teamIds as $teamId) {
            $count += $this->sideRosterCount($details, $side, $this->rosterSteamids($teamId));
        }

        return $count;
    }

    /**
     * Steamid3 des membres d'une équipe ETF2L (et équipes de France auxiliaires).
     *
     * @return array<string, bool>
     */
    private function rosterSteamids(int $teamId): array
    {
        if ($teamId <= 0) {
            return [];
        }

        $rows = DB::table('etf2l_players')
            ->where('team_id', $teamId)
            ->whereNotNull('steamid64')
            ->pluck('steamid64')
            ->all();

        $extra = DB::table('france_national_players')
            ->where('team_id', $teamId)
            ->whereNotNull('steamid64')
            ->pluck('steamid64')
            ->all();

        $steamids = [];
        foreach (array_merge($rows, $extra) as $steamid64) {
            // Ignore les valeurs invalides : une steamid64 est un entier (17 chiffres) ;
            // toSteamId3 lève une ValueError sur une entrée non numérique.
            if (!is_numeric($steamid64) || (preg_match('/^\d{17}$/', trim((string) $steamid64)) !== 1)) {
                continue;
            }
            $steamids[SteamId::toSteamId3(trim((string) $steamid64))] = true;
        }

        return $steamids;
    }

    private function matchName(int $teamId): ?string
    {
        if ($teamId <= 0) {
            return null;
        }

        return DB::table('etf2l_teams')->where('team_id', $teamId)->value('name');
    }

    private function namesMatch(string $logName, ?string $teamName): bool
    {
        if ($logName === '' || $teamName === null || $teamName === '') {
            return false;
        }

        return $logName === strtolower(trim($teamName))
            || str_contains($logName, strtolower(trim($teamName)))
            || str_contains(strtolower(trim($teamName)), $logName);
    }

    private function fetchLogDetail(int $logId): ?array
    {
        $details = JsonClient::get('https://logs.tf/api/v1/log/'.$logId);

        return is_array($details) ? $details : null;
    }

    private function logDate(int $logId): ?int
    {
        $details = $this->fetchLogDetail($logId);
        if ($details === null) {
            return null;
        }
        $date = (int) ($details['info']['date'] ?? $details['date'] ?? 0);

        return $date > 0 ? $date : null;
    }
}
