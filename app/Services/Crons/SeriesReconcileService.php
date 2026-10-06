<?php

declare(strict_types=1);

namespace App\Services\Crons;

use App\Models\SeriesRepository;
use App\Services\AdminLogger;
use App\Services\JsonClient;
use App\Services\SeriesScoreService;
use App\Services\SteamId;

/**
 * Réconciliation automatique des séries de matchs (playoffs) via logs.tf
 * (app:series-reconcile).
 *
 * Pour chaque série « live », le service cherche sur logs.tf les logs
 * récents contenant des joueurs des deux rosters (recherche par joueur),
 * puis applique au journal de la série chaque log qui remonte à cette
 * série. Le score (par map puis de série) est déduit du journal par
 * SeriesScoreService : ce service n'écrit que des faits bruts.
 *
 * Identification d'un log de la série (tous les filtres doivent passer) :
 *  - le log est postérieur au lancement du suivi de la série ;
 *  - la map du log (suffixe de version tronqué, ex. « cp_steel_f12 »)
 *    correspond à une map déclarée de la série ;
 *  - chaque équipe est présente à hauteur du roster moins une tolérance
 *    de mercs, ce qui écarte les scrims/pugs des mêmes joueurs ;
 *  - les couleurs RED/BLU du log sont non ambiguës (l'équipe de série la
 *    plus représentée dans chaque couleur), et distinctes.
 *
 * Les logs déjà vus (comptés ou rejetés) sont mémorisés par série : jamais
 * retéléchargés, jamais comptés deux fois.
 *
 * Les appels HTTP sont injectables (closures) pour les tests sans réseau,
 * sur le modèle d'OverlayStatsService.
 */
final class SeriesReconcileService
{
    private const SCRIPT_NAME = 'series_reconcile.php';

    /** Verrou anti-concurrence : une seule exécution à la fois (cron + panel admin). */
    private const LOCK_FILE = 'series_reconcile.lock';

    private const SEARCH_URL = 'https://logs.tf/api/v1/log';

    private const DETAIL_URL = 'https://logs.tf/api/v1/log/';

    /** Joueurs interrogés par équipe pour la recherche (robustesse aux mercs). */
    private const PLAYERS_PER_TEAM = 2;

    /** Mercs tolérés par équipe pour rattacher un log à la série. */
    private const MERC_TOLERANCE = 3;

    /** Recherche logs.tf par joueur : fn(steamid64): ?array. */
    private $searcher;

    /** Détail d'un log logs.tf : fn(log_id): ?array. */
    private $fetcher;

    /** Raison du dernier rejet d'un log (diagnostics, mémorisée par série). */
    private string $lastReject = '';

    public function __construct(?callable $searcher = null, ?callable $fetcher = null)
    {
        $this->searcher = $searcher ?? static fn (string $steamid64): ?array => JsonClient::get(
            self::SEARCH_URL.'?player='.urlencode($steamid64).'&limit=50'
        );
        $this->fetcher = $fetcher ?? static fn (int $logId): ?array => JsonClient::get(self::DETAIL_URL.$logId);
    }

    /**
     * Réconcilie toutes les séries « live ». Sans série en direct, la passe
     * est immédiate (aucun appel réseau) : la planification à la minute est
     * sans coût le reste du temps.
     */
    public function run(): string
    {
        $lock = fopen(hlfr_data_path(self::LOCK_FILE), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return 'Réconciliation des séries ignorée : une autre exécution est déjà en cours.';
        }

        try {
            $live = (new SeriesRepository)->allLive();
            if ($live === []) {
                return 'Aucune série en direct : rien à réconcilier.';
            }

            $applied = 0;
            $messages = [];

            foreach ($live as $series) {
                $result = $this->reconcile($series);
                $applied += $result['applied'];
                if ($result['message'] !== '') {
                    $messages[] = $result['message'];
                }
            }

            $summary = $applied > 0
                ? 'SUCCESS ('.$applied.' log(s) appliqué(s) : '.implode(' ; ', $messages).')'
                : 'Rien de nouveau : aucun log à appliquer.';
            if ($applied > 0) {
                AdminLogger::log(self::SCRIPT_NAME, null, $summary);
            }

            return $summary;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Réconcile une série : recherche des logs candidats, filtrage, application.
     *
     * @param  array<string, mixed>  $series
     * @return array{applied: int, message: string}
     */
    private function reconcile(array $series): array
    {
        $repo = new SeriesRepository;
        $token = (string) ($series['token'] ?? '');

        $rosters = [
            'red' => $this->rosterSteamId3($series, 'red'),
            'blue' => $this->rosterSteamId3($series, 'blue'),
        ];

        if ($rosters['red'] === [] || $rosters['blue'] === []) {
            return ['applied' => 0, 'message' => 'série '.$token.' sans roster complet'];
        }

        $startedAt = (int) ($series['started_at'] ?? $series['created_at'] ?? 0);
        $seen = $repo->seenLogs($token);
        $candidates = $this->searchCandidates($series, $startedAt, array_keys($seen));

        $applied = [];
        $seenUpdates = [];

        foreach ($candidates as $logId) {
            $details = ($this->fetcher)((int) $logId);
            $event = $this->buildEvent($series, $rosters, $startedAt, (int) $logId, $details);

            if ($event === null) {
                $seenUpdates[(string) $logId] = 'rejected:'.$this->lastReject;

                continue;
            }

            $repo->appendEvent($token, $event);
            $seenUpdates[(string) $logId] = 'applied';
            $applied[] = 'log #'.$logId.' → '.$event['map'].' ('.$event['winner'].')';
        }

        if ($seenUpdates !== []) {
            $repo->markSeenLogs($token, $seenUpdates);
        }

        // Une série arrivée à son terme (score atteint ou toutes maps décidées)
        // repasse en statut « finished » : le suivi cesse tout seul.
        $fresh = $repo->find($token);
        if ($fresh !== null && ($fresh['status'] ?? '') === 'live') {
            $state = (new SeriesScoreService)->compute($fresh);
            if ($state['finished']) {
                $fresh['status'] = 'finished';
                $repo->save($fresh);
            }
        }

        return ['applied' => count($applied), 'message' => implode(', ', $applied)];
    }

    /**
     * Cherche sur logs.tf les logs candidats des deux rosters, postérieurs au
     * lancement du suivi et pas encore vus. L'union des recherches par joueur
     * rend la détection robuste si l'un des joueurs interrogés est mercé.
     *
     * @param  array<string, mixed>  $series
     * @return array<int, int> IDs de logs candidats, triés (un log ne doit être
     *                         traité qu'une fois même si plusieurs recherches le remontent).
     */
    private function searchCandidates(array $series, int $startedAt, array $seenIds): array
    {
        $candidates = [];

        // Le décodage JSON cast les clés « 123 » en entiers : normaliser pour
        // que la comparaison stricte ci-dessous ne rate jamais un log vu.
        $seenIds = array_map(static fn (string|int $key): int => (int) $key, $seenIds);

        foreach (['red', 'blue'] as $team) {
            $players = is_array($series['teams'][$team]['players'] ?? null) ? $series['teams'][$team]['players'] : [];
            $players = array_slice($players, 0, self::PLAYERS_PER_TEAM);

            foreach ($players as $steamid64) {
                $response = ($this->searcher)((string) $steamid64);
                foreach (is_array($response['logs'] ?? null) ? $response['logs'] : [] as $log) {
                    $logId = (int) ($log['id'] ?? 0);
                    $date = (int) ($log['date'] ?? 0);

                    if ($logId === 0 || $date < $startedAt || in_array($logId, $seenIds, true)) {
                        continue;
                    }

                    $candidates[$logId] = $logId;
                }
            }
        }

        $sorted = array_values($candidates);
        sort($sorted);

        return $sorted;
    }

    /**
     * Valide un log logs.tf face à la série et construit l'événement « log »
     * du journal (gagnant et scores normalisés en équipes de série).
     *
     * @param  array<string, mixed>  $series
     * @param  array<string, array<string, true>>  $rosters  steamid3 par équipe de série
     * @return array<string, mixed>|null Événement, ou null si le log ne
     *                                   remonte pas à la série (raison dans $this->lastReject).
     */
    private function buildEvent(array $series, array $rosters, int $startedAt, int $logId, ?array $details): ?array
    {
        $this->lastReject = 'log_inaccessible';

        if ($details === null
            || ($details['success'] ?? false) !== true
            || ! is_array($details['players'] ?? null)
            || $details['players'] === []) {
            return null;
        }

        $info = is_array($details['info'] ?? null) ? $details['info'] : [];
        if ((int) ($info['date'] ?? 0) < $startedAt) {
            $this->lastReject = 'anterieur_au_suivi';

            return null;
        }

        $mapName = SeriesScoreService::matchSeriesMap($series, (string) ($info['map'] ?? ''));
        if ($mapName === null) {
            $this->lastReject = 'map_hors_serie';

            return null;
        }

        // Couverture des rosters par couleur : chaque équipe de série doit
        // être identifiable dans une couleur distincte, à la tolérance mercs.
        $coverage = ['red' => ['Red' => 0, 'Blue' => 0], 'blue' => ['Red' => 0, 'Blue' => 0]];
        foreach ($details['players'] as $steamid3 => $pData) {
            $color = (string) ($pData['team'] ?? '');
            if ($color !== 'Red' && $color !== 'Blue') {
                continue;
            }

            foreach ($rosters as $teamKey => $roster) {
                if (isset($roster[(string) $steamid3])) {
                    $coverage[$teamKey][$color]++;
                }
            }
        }

        $thresholds = [
            'red' => max(1, count($rosters['red']) - self::MERC_TOLERANCE),
            'blue' => max(1, count($rosters['blue']) - self::MERC_TOLERANCE),
        ];

        $colors = [];
        foreach (['red', 'blue'] as $teamKey) {
            $bestColor = null;
            $bestCount = 0;
            foreach (['Red', 'Blue'] as $color) {
                if ($coverage[$teamKey][$color] > $bestCount) {
                    $bestColor = $color;
                    $bestCount = $coverage[$teamKey][$color];
                }
            }

            if ($bestColor === null || $bestCount < $thresholds[$teamKey]) {
                $this->lastReject = 'roster_insuffisant';

                return null;
            }

            $colors[$teamKey] = $bestColor;
        }

        if ($colors['red'] === $colors['blue']) {
            $this->lastReject = 'couleurs_ambigues';

            return null;
        }

        $teamsRaw = is_array($details['teams'] ?? null) ? $details['teams'] : [];
        $colorScores = [
            'Red' => (int) ($teamsRaw['Red']['score'] ?? 0),
            'Blue' => (int) ($teamsRaw['Blue']['score'] ?? $teamsRaw['BLU']['score'] ?? 0),
        ];

        $seriesScores = [
            'red' => $colorScores[$colors['red']],
            'blue' => $colorScores[$colors['blue']],
        ];

        $winner = null;
        if ($seriesScores['red'] > $seriesScores['blue']) {
            $winner = 'red';
        } elseif ($seriesScores['blue'] > $seriesScores['red']) {
            $winner = 'blue';
        }

        $this->lastReject = '';

        return [
            'type' => 'log',
            'source' => 'logstf',
            'log_id' => $logId,
            'map' => $mapName,
            'winner' => $winner,
            'scores' => $seriesScores,
            'title' => (string) ($info['title'] ?? ''),
        ];
    }

    /**
     * Roster d'une équipe sous forme de set steamid3 (clés des joueurs des
     * réponses de l'API logs.tf).
     *
     * @param  array<string, mixed>  $series
     * @return array<string, true>
     */
    private function rosterSteamId3(array $series, string $team): array
    {
        $roster = [];
        $players = is_array($series['teams'][$team]['players'] ?? null) ? $series['teams'][$team]['players'] : [];
        foreach ($players as $steamid64) {
            $roster[SteamId::toSteamId3((string) $steamid64)] = true;
        }

        return $roster;
    }
}
