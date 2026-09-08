<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MatchLogRepository;
use App\Models\OfficialMatchRepository;
use App\Services\JsonClient;
use Illuminate\Support\Facades\DB;

/**
 * Moteur de stats multi-équipes : assemble la « fenêtre 3 dernières saisons »
 * de chaque équipe (union des fenêtres des joueurs du roster actuel), rattache
 * les logs.tf (auto via la page ETF2L, ou manuel), traite les logs et calcule
 * les agrégations équipe + joueur. C'est le futur backend des overlays.
 */
final class DuelStatsService
{
    /** Nombre maximal de matchs scrapés en un clic (« Tout récupérer »). */
    private const SCRAPE_BATCH_MAX = 25;

    /** Délai minimal entre deux appels HTTP (bonne citoyenneté logs.tf/ETF2L). */
    private const HTTP_DELAY_S = 1.1;

    /** Ne pas re-scraper un match sans log avant ce délai (secondes). */
    private const SCRAPE_RETRY_DELAY_S = 3600;

    private float $lastHttpAt = 0.0;

    public function __construct(
        private readonly Etf2lHistoryService $history,
        private readonly OfficialMatchRepository $repo,
        private readonly OfficialLogProcessor $processor,
    ) {}

    // ─── Constructions de la page ───────────────────────────────────────────

    /**
     * Mode de jeu d'une équipe : surcharge manuelle, sinon détection automatique.
     */
    public function modeFor(int $teamId, ?string $override): ?string
    {
        return in_array($override, ['9v9', '6s'], true) ? $override : $this->history->detectMode($teamId);
    }

    /**
     * Fenêtre de matchs d'une équipe (union des 3 dernières saisons de ses
     * joueurs du roster), utilisée pour l'affichage et les agrégations.
     *
     * @return array<int, array<string, mixed>>
     */
    public function teamWindow(int $teamId, ?string $mode): array
    {
        return $this->buildWindow($teamId, $this->roster($teamId), $mode);
    }

    /**
     * Construit le modèle complet d'une équipe pour la page.
     *
     * @return array<string, mixed>
     */
    public function buildTeam(int $teamId, ?string $overrideMode, ?string $addedBy = null): array
    {
        $teamRow = DB::table('etf2l_teams')->where('team_id', $teamId)->first();
        if ($teamRow === null) {
            // Équipe inconnue en base : on synchronise sa fiche + son roster via l'API.
            $this->history->syncTeam($teamId);
            $teamRow = DB::table('etf2l_teams')->where('team_id', $teamId)->first();
        }
        $blacklistedTeams = $this->repo->blacklistedTeamIds();

        $roster = $this->roster($teamId);
        $hasHistory = false;
        foreach ($roster as $p) {
            if (! $this->repo->hasPlayerHistory($p['steamid'])) {
                // Premier passage : on peuple l'historique des joueurs sans cache.
                $this->history->refreshPlayer($p['steamid'], false);
            } else {
                $hasHistory = true;
            }
        }

        $mode = $this->modeFor($teamId, $overrideMode);

        $window = $this->buildWindow($teamId, $roster, $mode);
        $matchIds = array_column($window, 'match_id');
        $logs = $this->repo->logsForMatches($matchIds);

        $playerRows = [];
        $stats = null;
        if ($window !== [] && $logs !== []) {
            [$stats, $playerRows] = $this->aggregate($teamId, $logs, $roster);
        }

        $processed = DB::table('processed_logs')->get()->mapWithKeys(
            static fn ($r): array => [(int) $r->id => true],
        )->all();

        $matches = [];
        $playersWithLog = [];
        foreach ($logs as $log) {
            $playersWithLog[(int) $log['match_id']] = true;
        }
        foreach ($window as $entry) {
            $matches[] = $this->matchEntry($entry, $logs, $processed, $playersWithLog, $blacklistedTeams);
        }

        return [
            'team_id' => $teamId,
            'name' => $teamRow !== null ? (string) $teamRow->name : 'Équipe #'.$teamId,
            'tag' => $teamRow !== null ? (string) ($teamRow->tag ?? '') : '',
            'country' => $teamRow !== null ? (string) ($teamRow->country ?? '') : 'unknown',
            'mode' => $mode,
            'mode_override' => in_array($overrideMode, ['9v9', '6s'], true),
            'blacklisted' => in_array($teamId, $blacklistedTeams, true),
            'has_history' => $hasHistory,
            'roster_count' => count($roster),
            'roster' => $roster,
            'stats' => $stats,
            'players' => $playerRows,
            'matches' => $matches,
        ];
    }

    /**
     * Récupère et prépare l'historique ETF2L des joueurs d'une équipe (utilisé
     * par le Job en arrière-plan pour ne pas bloquer la requête HTTP). No-op
     * pour les joueurs dont l'historique est déjà en cache.
     *
     * @param  callable(int, string):void|null  $onPlayer  Appelé au début de chaque joueur à traiter (index 0-based, nom).
     * @param  callable(int):void|null  $onPage  Appelé après chaque page (n° de page).
     */
    public function preloadHistory(int $teamId, ?callable $onPlayer = null, ?callable $onPage = null): void
    {
        $this->ensureTeamSynced($teamId);

        $index = 0;
        foreach ($this->roster($teamId) as $p) {
            if ($this->repo->hasPlayerHistory($p['steamid'])) {
                continue;
            }
            if ($onPlayer !== null) {
                $onPlayer($index++, (string) $p['name']);
            }
            $this->history->refreshPlayer($p['steamid'], false, $onPage);
        }
    }

    /**
     * Synchronise la fiche d'une équipe via l'API ETF2L si elle est inconnue.
     */
    public function ensureTeamSynced(int $teamId): void
    {
        $row = DB::table('etf2l_teams')->where('team_id', $teamId)->first();
        if ($row === null) {
            $this->history->syncTeam($teamId);
        }
    }

    /**
     * Nombre de joueurs du roster dont l'historique reste à pré-charger.
     */
    public function countPendingHistory(int $teamId): int
    {
        $this->ensureTeamSynced($teamId);

        $count = 0;
        foreach ($this->roster($teamId) as $p) {
            if (! $this->repo->hasPlayerHistory($p['steamid'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Roster actuel (etf2l_players + infos du site) avec visibilité lineup.
     *
     * @return array<int, array<string, mixed>>
     */
    private function roster(int $teamId): array
    {
        $rows = DB::table('etf2l_players')
            ->where('team_id', $teamId)
            ->whereNotNull('steamid64')
            ->select('player_id', 'name', 'role', 'steamid64')
            ->get();

        $roster = [];
        foreach ($rows as $row) {
            $steamid = SteamId::toSteamId3((string) $row->steamid64);
            if ($steamid === null) {
                continue;
            }
            $roster[] = [
                'steamid' => $steamid,
                'steamid64' => (string) $row->steamid64,
                'name' => (string) ($row->name ?? ''),
                'role' => (string) ($row->role ?? 'Member'),
            ];
        }

        if ($roster === []) {
            return [];
        }

        $steamids = array_column($roster, 'steamid');
        $infos = DB::table('players_info')
            ->whereIn('steamid', $steamids)
            ->get()
            ->keyBy('steamid');

        $lineup = $this->repo->lineup($teamId);

        foreach ($roster as &$p) {
            $info = $infos->get($p['steamid']);
            $resolved = trim((string) ($info->display_name ?? '')) !== ''
                ? (string) $info->display_name
                : (trim((string) ($info->name ?? '')) !== '' ? (string) $info->name : $p['name']);
            $p['name'] = $resolved !== '' ? $resolved : $p['name'];
            $p['avatar'] = $info !== null ? $info->avatar : null;
            $p['visible'] = $lineup[$p['steamid']] ?? true;
        }
        unset($p);

        usort($roster, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $roster;
    }

    /**
     * Fenêtre de matchs d'une équipe : union des 3 dernières saisons jouées
     * (avec cette équipe) de chaque joueur du roster actuel.
     *
     * @param  array<int, array<string, mixed>>  $roster
     * @return array<int, array<string, mixed>>
     */
    private function buildWindow(int $teamId, array $roster, ?string $mode): array
    {
        $byMatch = [];

        foreach ($roster as $player) {
            $steamid = $player['steamid'];
            $seasons = $this->repo->playerTeamSeasons($steamid, $teamId, $mode);
            if ($seasons === []) {
                continue;
            }
            $labelByComp = [];
            foreach ($seasons as $s) {
                $labelByComp[(int) $s['competition_id']] = $s['season_label'] ?? null;
            }
            $topIds = array_slice(array_map(static fn (array $s): int => (int) $s['competition_id'], $seasons), 0, 3);
            $topSet = array_flip($topIds);

            foreach ($this->repo->playerTeamMatches($steamid, $teamId, $mode) as $m) {
                $compId = (int) $m['competition_id'];
                if (! isset($topSet[$compId])) {
                    continue;
                }

                $mid = (int) $m['match_id'];
                if ($mid <= 0 || isset($byMatch[$mid])) {
                    continue;
                }

                $byMatch[$mid] = [
                    'match_id' => $mid,
                    'competition_id' => $compId,
                    'competition_name' => (string) ($m['competition_name'] ?? ''),
                    'season_label' => $labelByComp[$compId] ?? null,
                    'time' => (int) ($m['time'] ?? 0),
                    'round' => (string) ($m['round'] ?? ''),
                    'opponent_team_id' => $m['opponent_team_id'] !== null ? (int) $m['opponent_team_id'] : null,
                    'team_score' => isset($m['r1'], $m['r2'])
                        ? (((bool) $m['team_is_clan1']) ? (int) $m['r1'] : (int) $m['r2'])
                        : null,
                    'opponent_score' => isset($m['r1'], $m['r2'])
                        ? (((bool) $m['team_is_clan1']) ? (int) $m['r2'] : (int) $m['r1'])
                        : null,
                ];
            }
        }

        $entries = array_values($byMatch);
        usort($entries, static fn (array $a, array $b): int => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));

        $opIds = array_values(array_unique(array_filter(array_column($entries, 'opponent_team_id'))));
        $names = $opIds === [] ? [] : DB::table('etf2l_teams')
            ->whereIn('team_id', $opIds)
            ->pluck('name', 'team_id')
            ->all();

        foreach ($entries as &$e) {
            $opId = $e['opponent_team_id'];
            $e['opponent_name'] = $opId !== null
                ? (string) ($names[$opId] ?? 'Équipe #'.$opId)
                : '';
        }
        unset($e);

        return $entries;
    }

    /**
     * Enrichit une entrée de fenêtre avec les logs rattachés et leur statut.
     *
     * @param  array<int, array<string, mixed>>  $logsByMatch
     * @param  array<string, bool>  $processed
     * @param  array<string, bool>  $playersWithLog
     * @param  int[]  $blacklistedTeams
     * @return array<string, mixed>
     */
    private function matchEntry(array $entry, array $logsByMatch, array $processed, array $playersWithLog, array $blacklistedTeams): array
    {
        $matchLogs = [];
        foreach ($logsByMatch as $log) {
            if ((int) $log['match_id'] === (int) $entry['match_id']) {
                $matchLogs[] = [
                    'log_id' => (int) $log['log_id'],
                    'source' => (string) $log['source'],
                    'red_score' => $log['red_score'],
                    'blue_score' => $log['blue_score'],
                    'processed' => isset($processed[$log['log_id']]),
                    'blacklisted' => (bool) $log['blacklisted'],
                ];
            }
        }

        return array_merge($entry, [
            'logs' => $matchLogs,
            'has_log' => isset($playersWithLog[(int) $entry['match_id']]) || $matchLogs !== [],
            'log_missing' => ! isset($playersWithLog[(int) $entry['match_id']]) && $matchLogs === [],
            'blacklisted_teams' => $blacklistedTeams,
        ]);
    }

    // ─── Agrégations ────────────────────────────────────────────────────────

    /**
     * Calcule les stats d'équipe et les cartes joueurs sur les logs de la fenêtre,
     * en ne gardant que les lignes des joueurs qui étaient du côté de l'équipe.
     *
     * @param  array<int, array<string, mixed>>  $logs
     * @param  array<int, array<string, mixed>>  $roster
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function aggregate(int $teamId, array $logs, array $roster): array
    {
        $logIds = array_column($logs, 'log_id');
        $blacklist = $this->windowBlacklistIds();

        $rows = DB::table('player_matches as pm')
            ->join('official_match_logs as oml', 'oml.log_id', '=', 'pm.match_id')
            ->whereIn('pm.match_id', $logIds)
            ->when($blacklist !== [], fn ($q) => $q->whereNotIn('pm.match_id', $blacklist))
            ->where(function ($q) use ($teamId): void {
                $q->where(function ($r) use ($teamId): void {
                    $r->where('oml.red_team_id', $teamId)->where('pm.team', 'red');
                })->orWhere(function ($b) use ($teamId): void {
                    $b->where('oml.blue_team_id', $teamId)->where('pm.team', 'blue');
                });
            })
            ->select(
                'pm.steamid', 'pm.match_id', 'pm.kills', 'pm.deaths', 'pm.dmg', 'pm.assists',
                'pm.heal', 'pm.airshots', 'pm.captures', 'pm.length', 'pm.dapm', 'pm.won',
            )
            ->get()
            ->all();

        $total = $this->emptyCounters();
        $byPlayer = [];
        $matchIds = [];

        foreach ($rows as $row) {
            $sid = (string) $row->steamid;
            $matchIds[(int) $row->match_id] = true;
            if (! isset($byPlayer[$sid])) {
                $byPlayer[$sid] = $this->emptyCounters();
            }

            $byPlayer[$sid]['matches']++;
            foreach (['kills', 'deaths', 'dmg', 'assists', 'heal', 'airshots', 'captures', 'length'] as $k) {
                $v = (int) $row->{$k};
                $byPlayer[$sid][$k] += $v;
                $total[$k] += $v;
            }
            if ($row->won !== null) {
                $byPlayer[$sid]['decided']++;
                $total['decided']++;
                if ((int) $row->won === 1) {
                    $byPlayer[$sid]['wins']++;
                    $total['wins']++;
                }
            }
            if ((int) $row->length > 0) {
                $byPlayer[$sid]['dpmCount']++;
                $byPlayer[$sid]['dpmSum'] += (int) $row->dapm;
                $total['dpmCount']++;
                $total['dpmSum'] += (int) $row->dapm;
            }
        }

        $playerCards = [];
        foreach ($byPlayer as $sid => $p) {
            $playerCards[] = $this->finalize(array_merge($p, ['steamid' => $sid]));
        }

        // Classement : matchs puis DPM.
        usort($playerCards, static fn (array $a, array $b): int => ($b['matches'] ?? 0) <=> ($a['matches'] ?? 0));

        // Associer le roster pour l'affichage (nom, visibilité, avatar).
        $rosterBySteamid = [];
        foreach ($roster as $p) {
            $rosterBySteamid[$p['steamid']] = $p;
        }
        $playerDecorated = [];
        foreach ($playerCards as $card) {
            $p = $rosterBySteamid[$card['steamid']] ?? null;
            $playerDecorated[] = array_merge($card, [
                'name' => $p['name'] ?? $card['steamid'],
                'steamid64' => $p['steamid64'] ?? null,
                'role' => $p['role'] ?? '',
                'avatar' => $p['avatar'] ?? null,
                'visible' => $p['visible'] ?? true,
            ]);
        }

        // Total équipe : le nombre de « matchs » est le nombre de logs distincts
        // comptabilisés (nombre de matchs avec log dans la fenêtre).
        $total['matches'] = count($matchIds);

        return [$this->finalize($total, true), $playerDecorated];
    }

    /**
     * @return array<string, int>
     */
    private function emptyCounters(): array
    {
        return [
            'steamid' => '', 'matches' => 0, 'kills' => 0, 'deaths' => 0, 'dmg' => 0,
            'assists' => 0, 'heal' => 0, 'airshots' => 0, 'captures' => 0, 'length' => 0,
            'seconds' => 0, 'wins' => 0, 'decided' => 0, 'dpmSum' => 0, 'dpmCount' => 0,
        ];
    }

    /**
     * Formate des compteurs en stats lisibles (K/D, DPM, winrate…).
     *
     * @param  array<string, int>  $p
     * @return array<string, mixed>
     */
    private function finalize(array $p, bool $isTeam = false): array
    {
        $kills = (int) ($p['kills'] ?? 0);
        $deaths = (int) ($p['deaths'] ?? 0);
        $decided = (int) ($p['decided'] ?? 0);
        $dpmCount = max(1, (int) ($p['dpmCount'] ?? 0));
        $seconds = (int) ($p['length'] ?? $p['seconds'] ?? 0);

        // DPM d'équipe : dégâts par minute de jeu effective des joueurs.
        $dpm = $isTeam
            ? ($seconds > 0 ? round((int) ($p['dmg'] ?? 0) * 60 / $seconds, 0) : 0)
            : round((int) ($p['dpmSum'] ?? 0) / $dpmCount, 0);

        $kd = $deaths > 0 ? round($kills / $deaths, 2) : ($kills > 0 ? (float) $kills : 0.0);

        return [
            'steamid' => (string) ($p['steamid'] ?? ''),
            'matches' => (int) ($p['matches'] ?? 0),
            'kills' => $kills,
            'deaths' => $deaths,
            'dmg' => (int) ($p['dmg'] ?? 0),
            'assists' => (int) ($p['assists'] ?? 0),
            'heal' => (int) ($p['heal'] ?? 0),
            'airshots' => (int) ($p['airshots'] ?? 0),
            'captures' => (int) ($p['captures'] ?? 0),
            'kd' => $kd,
            'dpm' => $dpm,
            'winrate' => $decided > 0 ? round((int) ($p['wins'] ?? 0) * 100 / $decided, 0) : null,
            'wins' => (int) ($p['wins'] ?? 0),
            'losses' => $decided - (int) ($p['wins'] ?? 0),
            'draws' => max(0, (int) ($p['decided'] ?? 0) - (int) ($p['wins'] ?? 0)),
        ];
    }

    /**
     * Logs exclus des stats : blacklist individuelle + logs impliquant une
     * équipe blacklistée.
     *
     * @return int[]
     */
    private function windowBlacklistIds(): array
    {
        $ids = (new MatchLogRepository)->blacklistedIds();

        $teams = $this->repo->blacklistedTeamIds();
        if ($teams !== []) {
            $teamLogs = DB::table('official_match_logs')
                ->where(function ($q) use ($teams): void {
                    $q->whereIn('red_team_id', $teams)->orWhereIn('blue_team_id', $teams);
                })
                ->pluck('log_id')
                ->map('intval')
                ->all();
            $ids = array_merge($ids, $teamLogs);
        }

        return array_values(array_unique($ids));
    }

    // ─── Rattachement des logs ──────────────────────────────────────────────

    /**
     * Scrape les pages ETF2L des matchs de la fenêtre sans log (limit par clic).
     *
     * @return array{checked: int, attached: int, skipped: int, errors: int}
     */
    public function scrapeMissing(int $teamId, ?string $mode, ?int $matchId = null, ?string $addedBy = null): array
    {
        $roster = $this->roster($teamId);
        $window = $this->buildWindow($teamId, $roster, $mode);

        $attachedIds = $this->repo->logsForMatches(array_column($window, 'match_id'));
        $withLog = [];
        foreach ($attachedIds as $log) {
            $withLog[(int) $log['match_id']] = true;
        }

        $recently = DB::table('official_match_scrape')->pluck('checked_at', 'match_id')->all();

        $result = ['checked' => 0, 'attached' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($window as $entry) {
            $mid = (int) $entry['match_id'];
            if ($matchId !== null && $mid !== $matchId) {
                continue;
            }
            if (isset($withLog[$mid])) {
                continue;
            }
            if ($matchId === null && isset($recently[$mid]) && time() - (int) $recently[$mid] < self::SCRAPE_RETRY_DELAY_S) {
                continue;
            }

            $logIds = $this->scrapeMatchPage($mid);
            DB::table('official_match_scrape')
                ->upsert(['match_id' => $mid, 'checked_at' => time()], ['match_id'], ['checked_at']);

            if ($logIds === []) {
                $result['checked']++;
                $result['skipped']++;

                continue;
            }

            foreach ($logIds as $logId) {
                if ($this->repo->existsLog($logId)) {
                    $result['skipped']++;

                    continue;
                }
                $ok = $this->attachLogToMatch($logId, $mid, (int) $entry['opponent_team_id'], $mode, 'auto', $addedBy);
                if ($ok) {
                    $result['attached']++;
                } else {
                    $result['errors']++;
                }
            }

            $result['checked']++;
            if (($matchId === null) && $result['checked'] >= self::SCRAPE_BATCH_MAX) {
                break;
            }
        }

        return $result;
    }

    /**
     * Attache manuellement un log.tf à un match (URL ou ID), puis le traite.
     *
     * @return array{ok: bool, message: string}
     */
    public function attachManual(string $logInput, int $matchId, int $teamId, ?string $mode, ?string $addedBy): array
    {
        $logId = $this->parseLogId($logInput);
        if ($logId === null) {
            return ['ok' => false, 'message' => 'URL ou ID logs.tf invalide.'];
        }

        $match = $this->windowMatch($teamId, $matchId);
        if ($match === null) {
            return ['ok' => false, 'message' => 'Match hors de la fenêtre de l\'équipe.'];
        }

        if ($this->repo->existsLog($logId)) {
            return ['ok' => false, 'message' => "Le log {$logId} est déjà rattaché à un match officiel."];
        }

        if ($this->attachLogToMatch($logId, $matchId, (int) $match['opponent_team_id'], $mode, 'manual', $addedBy)) {
            return ['ok' => true, 'message' => "Log {$logId} rattaché au match {$matchId}."];
        }

        return ['ok' => false, 'message' => "Log {$logId} introuvable sur logs.tf."];
    }

    /**
     * Résout le match d'une équipe dans sa fenêtre (par match_id).
     *
     * @return array<string, mixed>|null
     */
    private function windowMatch(int $teamId, int $matchId): ?array
    {
        $roster = $this->roster($teamId);

        foreach ($this->buildWindow($teamId, $roster, null) as $entry) {
            if ((int) $entry['match_id'] === $matchId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Vérifie l'existence d'un log et le rattache au match avec attribution
     * Red/Blue, puis le traite immédiatement (stats joueurs).
     */
    private function attachLogToMatch(int $logId, int $matchId, int $opponentId, ?string $mode, string $source, ?string $addedBy): bool
    {
        $details = $this->fetchLogDetail($logId);
        if ($details === null) {
            return false;
        }

        $category = ($mode !== null && in_array($mode, ['9v9', '6s'], true)) ? $mode : $this->categoryFromLog($details);
        [$redTeamId, $blueTeamId] = $this->resolveSides($details, $matchId, $opponentId);

        $this->repo->attachLog([
            'log_id' => $logId,
            'match_id' => $matchId,
            'category' => $category,
            'red_team_id' => $redTeamId,
            'blue_team_id' => $blueTeamId,
            'source' => $source,
            'added_by' => $addedBy,
        ]);

        // Traitement immédiat des stats joueurs (scores, dates, player_matches).
        $expectedDate = (int) DB::table('player_season_matches')->where('match_id', $matchId)->value('time');
        $this->processor->process($logId, $category, $expectedDate > 0 ? $expectedDate : null);

        return true;
    }

    /**
     * Recalcule les stats : traite tous les logs de la fenêtre de l'équipe
     * qui n'ont pas encore été intégrés à player_matches.
     *
     * @return array{matched: int, processed: int, skipped: int, errors: int}
     */
    public function recompute(int $teamId, ?string $mode): array
    {
        $roster = $this->roster($teamId);
        $window = $this->buildWindow($teamId, $roster, $mode);
        $logs = $this->repo->logsForMatches(array_column($window, 'match_id'));

        $result = ['matched' => count($logs), 'processed' => 0, 'skipped' => 0, 'errors' => 0];

        $blacklist = (new MatchLogRepository)->blacklistedIds();
        foreach ($logs as $log) {
            $logId = (int) $log['log_id'];
            if (in_array($logId, $blacklist, true)) {
                $result['skipped']++;

                continue;
            }

            $expectedDate = (int) DB::table('player_season_matches')->where('match_id', (int) $log['match_id'])->value('time');
            try {
                if ($this->processor->process($logId, (string) $log['category'], $expectedDate > 0 ? $expectedDate : null)) {
                    $result['processed']++;
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                error_log('Traitement log officiel '.$logId.' : '.$e->getMessage());
                $result['errors']++;
            }

            usleep(200000);
        }

        return $result;
    }

    /**
     * Force le rafraîchissement de l'historique ETF2L des joueurs du roster
     * (invalidation du cache joueur).
     *
     * @return array{players: int, fetched: int}
     */
    public function refreshHistory(int $teamId, bool $force = true): array
    {
        $roster = $this->roster($teamId);
        $players = count($roster);
        $fetched = 0;

        foreach ($roster as $p) {
            $count = $this->history->refreshPlayer($p['steamid'], $force);
            $fetched += $count['stored'];
        }

        return ['players' => $players, 'fetched' => $fetched];
    }

    // ─── Helpers de scraping / attribution ──────────────────────────────────

    /**
     * @return int[] IDs logs.tf présents dans la page ETF2L d'un match.
     */
    private function scrapeMatchPage(int $matchId): array
    {
        $raw = $this->httpRaw('https://etf2l.org/matches/'.$matchId.'/');
        if ($raw['body'] === null) {
            return [];
        }

        $ids = [];
        if (preg_match_all('#logs\.tf/(\d+)#i', $raw['body'], $matches)) {
            foreach ($matches[1] as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Catégorie du log via l'API logs.tf (si le mode n'est pas connu).
     */
    private function categoryFromLog(array $details): string
    {
        $title = strtolower((string) ($details['info']['title'] ?? ''));
        if (str_contains($title, '[6s]') || str_contains($title, '6s ')) {
            return '6s';
        }

        return '9v9';
    }

    /**
     * Attribution Red/Blue aux deux équipes d'un match à partir du log :
     * majorité de roster sur le camp, sinon correspondance des noms d'équipe.
     *
     * @param  array<string, mixed>  $details
     * @return array{0: int, 1: int} [redTeamId, blueTeamId]
     */
    private function resolveSides(array $details, int $teamId, int $opponentId): array
    {
        $roster = $this->rosterSteamidSet($teamId);

        $redCount = $this->sideRosterCount($details, 'red', $roster);
        $blueCount = $this->sideRosterCount($details, 'blue', $roster);

        if ($redCount + $blueCount > 0) {
            if ($redCount >= $blueCount) {
                return [$teamId, $opponentId];
            }

            return [$opponentId, $teamId];
        }

        // Fallback sur les noms d'équipe du log.
        $redName = strtolower(trim((string) ($details['teams']['Red']['name'] ?? '')));
        $blueName = strtolower(trim((string) ($details['teams']['Blue']['name'] ?? '')));

        $teamName = strtolower((string) DB::table('etf2l_teams')->where('team_id', $teamId)->value('name'));
        $oppName = strtolower((string) DB::table('etf2l_teams')->where('team_id', $opponentId)->value('name'));

        if ($redName !== '' && $redName === $teamName) {
            return [$teamId, $opponentId];
        }
        if ($redName !== '' && $redName === $oppName) {
            return [$opponentId, $teamId];
        }
        if ($blueName !== '' && $blueName === $teamName) {
            return [$opponentId, $teamId];
        }
        if ($blueName !== '' && $blueName === $oppName) {
            return [$teamId, $opponentId];
        }

        return [$teamId, $opponentId];
    }

    /**
     * @return array<string, bool> steamid3 => true
     */
    private function rosterSteamidSet(int $teamId): array
    {
        $set = [];
        foreach ($this->history->rosterSteamids($teamId) as $steamid) {
            $set[$steamid] = true;
        }

        return $set;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, bool>  $roster
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

    private function fetchLogDetail(int $logId): ?array
    {
        $this->throttle();

        $details = JsonClient::get('https://logs.tf/api/v1/log/'.$logId);

        return is_array($details) && isset($details['players']) ? $details : null;
    }

    /**
     * @return array{body: string|null, http_code: int}
     */
    private function httpRaw(string $url): array
    {
        $this->throttle();

        return JsonClient::getRaw($url, 20, 'Highlander France Bot/1.0');
    }

    private function throttle(): void
    {
        $elapsed = microtime(true) - $this->lastHttpAt;
        if ($this->lastHttpAt > 0 && $elapsed < self::HTTP_DELAY_S) {
            usleep((int) ((self::HTTP_DELAY_S - $elapsed) * 1e6));
        }
        $this->lastHttpAt = microtime(true);
    }

    private function parseLogId(string $input): ?int
    {
        if (preg_match('#(?:\/|^)(\d{4,10})\s*$#', trim($input), $m)) {
            return (int) $m[1];
        }
        if (preg_match('#logs\.tf/(\d{4,10})#i', $input, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
