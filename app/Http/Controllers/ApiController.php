<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlayerRepository;
use App\Services\LiveMatches;
use App\Services\LogsTfApi;
use App\Services\SteamId;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ApiController extends Controller
{
    /**
     * Matchs en cours (cache alimenté par le plugin hlfr_live_match).
     * GET /api/live-matches
     */
    public function liveMatches(): JsonResponse
    {
        $matches = [];

        foreach (LiveMatches::all() as $entry) {
            $matches[] = LiveMatches::enrich($entry);
        }

        return response()
            ->json(['data' => $matches])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Statistiques globales de la page d'accueil.
     * Le compteur de membres Discord (cache_discord_stats.json) est fusionné
     * s'il existe ; sinon la clé members est absente et la vue garde son fallback.
     *
     * Si le cache généré par le CRON est absent (déploiement à froid), on le
     * recalcule depuis la base (matches_cache, hors blacklist et logs courts)
     * pour que l'accueil s'affiche immédiatement, le CRON écrasant ensuite
     * le fichier avec les chiffres exacts.
     */
    public function indexStats(): JsonResponse
    {
        $cacheFile = hlfr_data_path('cache_hlfr_stats.json');

        $stats = null;
        if (is_file($cacheFile)) {
            $stats = json_decode((string) file_get_contents($cacheFile), true);
        }

        if (! is_array($stats)) {
            $stats = $this->computeIndexStatsFallback();
            if ($stats !== null) {
                @file_put_contents($cacheFile, json_encode($stats), LOCK_EX);
            }
        }
        $stats = $stats ?? [];

        $discordCacheFile = hlfr_data_path('cache_discord_stats.json');
        if (is_file($discordCacheFile)) {
            $discord = json_decode((string) file_get_contents($discordCacheFile), true);

            if (is_array($discord) && isset($discord['members']) && is_numeric($discord['members'])) {
                $stats['members'] = (int) $discord['members'];
            }
        }

        return response()->json(['data' => $stats]);
    }

    /**
     * Fallback rapide (sans appel à logs.tf) calqué sur la logique de
     * UpdateIndexStatsService : matchs en cache, hors blacklist, hors logs
     * courts, sans les 4 plus anciens de la liste.
     *
     * @return array<string, int>|null null si aucune donnée exploitable.
     */
    private function computeIndexStatsFallback(): ?array
    {
        try {
            $minLength = (int) config('hlfr.min_match_length', 300);

            $subOldest = \Illuminate\Support\Facades\DB::table('matches_cache')
                ->orderBy('match_id')
                ->limit(4)
                ->pluck('match_id');

            $ids = \Illuminate\Support\Facades\DB::table('matches_cache')
                ->where('length', '>', 0)
                ->where('length', '>=', $minLength)
                ->whereNotIn('match_id', $subOldest)
                ->whereNotIn('match_id', function ($q): void {
                    $q->select('log_id')->from('log_blacklist');
                })
                ->pluck('match_id');

            if ($ids->isEmpty()) {
                return null;
            }

            $row = \Illuminate\Support\Facades\DB::table('matches_cache')
                ->whereIn('match_id', $ids)
                ->selectRaw('COUNT(*) AS nb, COALESCE(SUM(length), 0) AS total')
                ->first();

            if ($row === null) {
                return null;
            }

            return [
                'matches' => (int) $row->nb,
                'hours' => (int) round((float) $row->total / 3600),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Logs "Highlander France" (Match Stats), hors blacklist.
     */
    public function logs(): JsonResponse
    {
        return response()->json((new LogsTfApi())->filteredLogs());
    }

    /**
     * Cache du leaderboard (généré par les crons).
     * GET /api/leaderboard?mode=9v9|6s&category=matches|kills|heal|dpm
     */
    public function leaderboard(Request $request): Response|JsonResponse
    {
        $mode = (string) $request->query('mode', '9v9');
        $category = (string) $request->query('category', 'matches');

        if (! in_array($mode, ['9v9', '6s'], true) || ! in_array($category, ['matches', 'kills', 'heal', 'dpm'], true)) {
            return response()->json(['error' => 'Paramètres invalides.'], 400);
        }

        $suffix = $category === 'matches' ? '' : '_' . $category;
        $file = hlfr_data_path('leaderboard_cache_' . $mode . $suffix . '.json');

        // Déploiement à froid : le cache n'existe pas encore (généré par le CRON).
        // On le régénère à la volée (requêtes DB uniquement, pas d'API externe)
        // pour que le Hall of Fame s'affiche dès la première visite.
        if (! is_file($file)) {
            try {
                (new \App\Services\Crons\GenerateJsonService())->run();
            } catch (\Throwable) {
                // On tente de servir quand même ci-dessous ; sinon 404.
            }
        }

        if (! is_file($file)) {
            return response()->json(['error' => 'Cache du leaderboard introuvable.'], 404);
        }

        return response(
            (string) file_get_contents($file),
            200,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /**
     * Recherche de joueurs (Hall of Fame).
     * GET /api/search-players?q=...
     */
    public function searchPlayers(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $results = [];
        foreach ((new PlayerRepository())->search($query) as $player) {
            $results[] = [
                'steamid' => SteamId::toSteamId64($player['steamid']),
                'name' => !empty($player['display_name']) ? $player['display_name'] : $player['name'],
                'avatar' => $player['avatar'],
            ];
        }

        return response()->json($results);
    }
}
