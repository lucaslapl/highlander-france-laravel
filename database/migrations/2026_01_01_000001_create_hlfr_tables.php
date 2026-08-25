<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schéma métier Highlander France (port de la base SQLite legacy).
 * Les noms de tables/colonnes sont conservés à l'identique pour que les
 * requêtes des services et le pipeline CRON restent compatibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Joueurs (profils Steam + rôles staff).
        Schema::create('players_info', function (Blueprint $table) {
            $table->string('steamid')->primary();
            $table->string('name')->nullable();
            $table->string('avatar', 500)->nullable();
            $table->unsignedBigInteger('last_updated')->nullable();
            $table->string('display_name')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->string('country', 16)->default(null)->nullable();
            $table->boolean('country_locked')->default(0);
            $table->boolean('name_changed')->default(0);
            $table->boolean('is_founder')->default(0);
            $table->boolean('is_mentor')->default(0);
            $table->boolean('is_mixer')->default(0);
            $table->boolean('is_moderator')->default(0);
            $table->boolean('is_admin')->default(0);

            $table->index(['display_name', 'name']);
            $table->index('created_at');
        });

        // Compteur de matchs par joueur et mode.
        Schema::create('player_stats', function (Blueprint $table) {
            $table->string('steamid');
            $table->integer('count')->default(0);
            $table->string('game_mode')->default('9v9');
            $table->primary(['steamid', 'game_mode']);
        });

        // Une ligne par log logs.tf traité.
        Schema::create('processed_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
        });

        // Détail par joueur et par match (stats logs.tf).
        Schema::create('player_matches', function (Blueprint $table) {
            $table->string('steamid');
            $table->unsignedBigInteger('match_id');
            $table->text('map_name')->nullable();
            $table->string('class_played', 64)->nullable();
            $table->string('game_mode')->default('9v9');
            $table->integer('dmg')->default(0);
            $table->integer('kills')->default(0);
            $table->integer('deaths')->default(0);
            $table->integer('assists')->default(0);
            $table->integer('suicides')->default(0);
            $table->integer('heal')->default(0);
            $table->integer('medkits')->default(0);
            $table->integer('ubers')->default(0);
            $table->integer('drops')->default(0);
            $table->integer('backstabs')->default(0);
            $table->integer('headshots')->default(0);
            $table->integer('longest_killstreak')->default(0);
            $table->text('classes_killed')->nullable();
            $table->integer('length')->default(0);
            $table->integer('dapm')->default(0);
            $table->integer('dmg_taken')->default(0);
            $table->integer('medkits_hp')->default(0);
            $table->integer('airshots')->default(0);
            $table->integer('captures')->default(0);
            $table->tinyInteger('won')->nullable()->default(null);
            $table->string('team', 8)->default(null)->nullable();
            $table->primary(['steamid', 'match_id']);

            $table->index('match_id');
            $table->index(['game_mode', 'match_id']);
        });

        // Cache durées des logs (page d'accueil / index stats).
        Schema::create('matches_cache', function (Blueprint $table) {
            $table->unsignedBigInteger('match_id')->primary();
            $table->integer('length')->nullable();
        });

        // Dates des logs (graphiques dashboard, profils, sitemap).
        Schema::create('log_dates', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary();
            $table->unsignedBigInteger('date')->nullable();
        });

        // Blacklist des logs exclus des statistiques.
        Schema::create('log_blacklist', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary();
            $table->text('reason')->nullable();
            $table->string('added_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Cache secondaire de durées (panel admin).
        Schema::create('log_length_cache', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary();
            $table->integer('length')->nullable();
        });

        // Scores RED / BLU par match.
        Schema::create('match_scores', function (Blueprint $table) {
            $table->unsignedBigInteger('match_id')->primary();
            $table->integer('red_score')->default(0);
            $table->integer('blue_score')->default(0);
        });

        // Agenda ETF2L des équipes françaises.
        Schema::create('etf2l_matches', function (Blueprint $table) {
            $table->unsignedBigInteger('match_id')->primary();
            $table->text('team1_name')->nullable();
            $table->text('team2_name')->nullable();
            $table->unsignedBigInteger('match_date')->nullable();
            $table->text('competition_name')->nullable();
            $table->string('team1_country', 64)->default(null)->nullable();
            $table->string('team2_country', 64)->default(null)->nullable();
            $table->unsignedBigInteger('team1_id')->default(null)->nullable();
            $table->unsignedBigInteger('team2_id')->default(null)->nullable();
            $table->mediumText('maps')->default(null)->nullable();
            $table->integer('r1')->default(null)->nullable();
            $table->integer('r2')->default(null)->nullable();
            $table->mediumText('map_results')->default(null)->nullable();

            $table->index('match_date');
            $table->index('team1_id');
            $table->index('team2_id');
        });

        // Équipes ETF2L (rosters).
        Schema::create('etf2l_teams', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->primary();
            $table->text('name')->nullable();
            $table->string('country', 64)->default(null)->nullable();
            $table->string('tag', 64)->default(null)->nullable();
        });

        Schema::create('etf2l_players', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('player_id');
            $table->text('name')->nullable();
            $table->string('role', 64)->default(null)->nullable();
            $table->string('country', 64)->default(null)->nullable();
            $table->string('steamid64', 32)->default(null)->nullable();
            $table->primary(['team_id', 'player_id']);

            $table->index('steamid64');
        });

        // Cache HTTP de l'API ETF2L (rate-limit friendly).
        Schema::create('etf2l_api_cache', function (Blueprint $table) {
            $table->string('url', 512)->primary();
            $table->longText('payload');
            $table->unsignedBigInteger('fetched_at');

            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etf2l_api_cache');
        Schema::dropIfExists('etf2l_players');
        Schema::dropIfExists('etf2l_teams');
        Schema::dropIfExists('etf2l_matches');
        Schema::dropIfExists('match_scores');
        Schema::dropIfExists('log_length_cache');
        Schema::dropIfExists('log_blacklist');
        Schema::dropIfExists('log_dates');
        Schema::dropIfExists('matches_cache');
        Schema::dropIfExists('player_matches');
        Schema::dropIfExists('processed_logs');
        Schema::dropIfExists('player_stats');
        Schema::dropIfExists('players_info');
    }
};
