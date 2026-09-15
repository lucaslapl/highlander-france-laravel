<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlayerRepository;
use App\Services\AdminLogger;
use App\Services\AvatarCache;
use App\Services\SteamId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class WarmAvatarsCommand extends Command
{
    protected $signature = 'app:warm-avatars {--limit=50 : nombre max de joueurs inscrits à pré-chauffer}';

    protected $description = 'Pré-télécharge les avatars Steam en statique (public/storage/avatars) pour éviter la rafale PHP/nginx';

    public function handle(): int
    {
        set_time_limit(300);

        $logToken = AdminLogger::log('warm_avatars.php');

        try {
            [$ok, $skip, $fail] = $this->warm();
        } catch (\Throwable $e) {
            AdminLogger::log('warm_avatars.php', $logToken, 'FAILED (' . $e->getMessage() . ')');
            $this->error('Erreur lors du pré-chauffage des avatars : ' . $e->getMessage());

            return self::FAILURE;
        }

        AdminLogger::log('warm_avatars.php', $logToken, "SUCCESS ({$ok} téléchargés, {$skip} déjà en cache, {$fail} échecs)");
        $this->info("Avatars pré-chauffés : {$ok} téléchargés, {$skip} déjà en cache, {$fail} échecs.");

        return self::SUCCESS;
    }

    private function warm(): array
    {
        $repo = new PlayerRepository;
        $limit = max(1, min((int) $this->option('limit'), 200));

        $targets = [];

        foreach ($repo->latestRegistered(20) as $row) {
            $steamid64 = SteamId::toSteamId64((string) $row['steamid']);
            if ($steamid64 !== null) {
                $targets[$steamid64] = (string) ($row['avatar'] ?? '');
            }
        }

        foreach ($repo->staffMembers() as $member) {
            $steamid64 = SteamId::toSteamId64((string) $member['steamid']);
            if ($steamid64 !== null) {
                $targets[$steamid64] = (string) ($member['avatar'] ?? '');
            }
        }

        $rows = DB::table('players_info')
            ->select('steamid', 'avatar')
            ->whereNotNull('created_at')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $steamid64 = SteamId::toSteamId64((string) $row->steamid);
            if ($steamid64 !== null && ! isset($targets[$steamid64])) {
                $targets[$steamid64] = (string) ($row->avatar ?? '');
            }
        }

        $ok = 0;
        $skip = 0;
        $fail = 0;

        foreach ($targets as $steamid64 => $source) {
            $steamid64 = (string) $steamid64;
            if (AvatarCache::urlFor($steamid64) !== '/img/avatar/'.$steamid64) {
                $skip++;
                continue;
            }

            if ($source === '') {
                $fail++;
                continue;
            }

            if (AvatarCache::warm($steamid64, $source) !== null) {
                $ok++;
            } else {
                $fail++;
            }

            usleep(100000);
        }

        return [$ok, $skip, $fail];
    }
}
