<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Niveau "réel" des joueurs par mode de jeu, calculé depuis leurs résultats
 * ETF2L (app:compute-player-levels) : moyenne des tiers des 3 dernières
 * compétitions jouées avec leur équipe officielle (matchs de remplacement exclus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_levels', function (Blueprint $table) {
            $table->string('steamid');
            $table->string('game_mode', 8)->default('9v9');
            $table->float('tier_moyen')->nullable();
            $table->string('division_label', 64)->default(null)->nullable();
            $table->integer('nb_matchs_comptes')->default(0);
            $table->integer('nb_competitions')->default(0);
            $table->unsignedBigInteger('last_match_time');
            $table->unsignedBigInteger('computed_at');

            $table->primary(['steamid', 'game_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_levels');
    }
};
