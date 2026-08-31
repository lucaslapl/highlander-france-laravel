<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Crons\ComputePlayerLevelsService;
use App\Services\Crons\ComputePlayerPalmaresService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

final class ComputePlayerEtf2lDataJob
{
    use Dispatchable;

    public function __construct(public readonly string $steamid3) {}

    public function handle(): void
    {
        try {
            (new ComputePlayerLevelsService)->computeForPlayer($this->steamid3);
        } catch (\Throwable $e) {
            Log::warning('ComputePlayerEtf2lDataJob niveaux échoué '.$this->steamid3.' : '.$e->getMessage());
        }

        try {
            (new ComputePlayerPalmaresService)->computeForPlayer($this->steamid3);
        } catch (\Throwable $e) {
            Log::warning('ComputePlayerEtf2lDataJob palmarès échoué '.$this->steamid3.' : '.$e->getMessage());
        }
    }
}
