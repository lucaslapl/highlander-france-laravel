<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OfficialMatchRepository;
use App\Services\DuelStatsService;
use App\Services\Etf2lHistoryService;
use App\Services\OfficialLogProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Recalcule hors de la requête HTTP les stats de deux équipes ETF2L, en mettant
 * à jour une ligne stats_duel_runs interrogée par le frontend (barre de charge).
 */
final class ComputeTeamStatsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    /** Part de la barre (en %) réservée au pré-chargement de l'historique ETF2L. */
    private const HISTORY_SHARE_PERCENT = 85;

    public function __construct(
        public readonly int $t1,
        public readonly int $t2,
        public readonly ?string $mode1,
        public readonly ?string $mode2,
        public readonly string $addedBy,
        public readonly ?int $runId = null,
    ) {}

    public function handle(): void
    {
        $service = new DuelStatsService(new Etf2lHistoryService, new OfficialMatchRepository, new OfficialLogProcessor);

        $runId = $this->runId ?? DB::table('stats_duel_runs')->insertGetId([
                't1' => $this->t1,
                't2' => $this->t2,
                'mode1' => $this->mode1,
                'mode2' => $this->mode2,
                'status' => 1,
                'progress' => 0,
                'message' => 'Préparation…',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $progress = function (int $value, string $message) use ($runId): void {
            DB::table('stats_duel_runs')->where('id', $runId)->update([
                'progress' => max(0, min(99, $value)),
                'message' => $message,
                'updated_at' => now(),
            ]);
        };

        try {
            $totalPending = $service->countPendingHistory($this->t1)
                + ($this->t1 !== $this->t2 ? $service->countPendingHistory($this->t2) : 0);

            $teams = [];
            $done = 0;

            $teamModes = [$this->mode1, $this->mode2];
            foreach ($this->teamIds() as $i => $teamId) {
                $mode = $teamModes[$i] ?? '';
                $mode = $mode !== '' && $mode !== null ? $mode : null;

                $service->preloadHistory(
                    $teamId,
                    function (int $idx, string $name) use ($progress, &$done, $totalPending): void {
                        $done++;
                        $seed = $totalPending > 0
                            ? (int) round($done / $totalPending * self::HISTORY_SHARE_PERCENT)
                            : self::HISTORY_SHARE_PERCENT;
                        $progress($seed, 'Préchargement historique ETF2L ('.$done.'/'.$totalPending.') — '.$name.'…');
                    },
                );

                $progress(self::HISTORY_SHARE_PERCENT + 5, 'Assemblage des stats…');
                $teams[$i] = $service->buildTeam($teamId, $mode, $this->addedBy);
                $progress(self::HISTORY_SHARE_PERCENT + 10, 'Stats de l\'équipe '.($i + 1).' prêtes…');
            }

            $progress(98, 'Terminé…');

            DB::table('stats_duel_runs')->where('id', $runId)->update([
                'status' => 2,
                'progress' => 100,
                'message' => 'Terminé',
                'result' => json_encode([
                    'teamA' => $teams[0] ?? null,
                    'teamB' => $teams[1] ?? null,
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('stats_duel_runs')->where('id', $runId)->update([
                'status' => 3,
                'message' => $e->getMessage(),
                'updated_at' => now(),
            ]);
            error_log('Stats duel job : '.$e->getMessage());
        }
    }

    /**
     * @return array<int, int>
     */
    private function teamIds(): array
    {
        if ($this->t1 === $this->t2) {
            return [$this->t1];
        }

        return [$this->t1, $this->t2];
    }
}