<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Palmarès ETF2L des joueurs : classements finaux et résultats de playoffs
 * significatifs (finales, demi-finales, etc.), calculés depuis les résultats
 * API et les tables de compétition (app:compute-player-palmares).
 *
 * Seules les saisons avec un résultat positif sont stockées : podium dans le
 * classement final (ach = 1/2/3) ou participation à un round de playoffs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_palmares', function (Blueprint $table) {
            $table->string('steamid');
            $table->unsignedInteger('competition_id');
            $table->string('game_mode', 8)->default('9v9');
            $table->string('competition_name', 200)->default('');
            $table->string('team_name', 200)->default('');
            $table->unsignedInteger('team_id')->default(0);
            $table->string('division_name', 100)->default('');
            $table->unsignedTinyInteger('tier')->default(0);
            $table->unsignedTinyInteger('placement')->nullable()->default(null);
            $table->string('playoff_round', 50)->nullable()->default(null);
            $table->boolean('won_playoff')->default(false);
            $table->unsignedInteger('computed_at');

            $table->primary(['steamid', 'competition_id']);
            $table->index('game_mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_palmares');
    }
};
