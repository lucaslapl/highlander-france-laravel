<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables du nouveau moteur de stats multi-équipes (remplace ligue-logs + overlays).
 *
 * - official_seasons      : cache des compétitions de saison ETF2L (par mode de jeu).
 * - player_season_matches : cache de l'historique officiel d'un joueur (résultats
 *                           /player/{id64}/results, catégories « Season » uniquement),
 *                           avec l'équipe effective dans laquelle il jouait
 *                           (was_in_team) et les scores officiels r1/r2.
 * - official_match_logs   : rattachement des logs.tf aux matchs officiels (remplace
 *                           le rôle de la table official_logs, avec attribution
 *                           Red/Blue aux deux équipes du match).
 * - stat_lineup           : visibilité d'un joueur pour l'équipe (qui joue le
 *                           « match du jour », pour masquer les remplaçants).
 *
 * Compatible MySQL/MariaDB et SQLite (le dev local repart d'une base fraîche ;
 * la vieille base SQLite « test_palmares » n'est plus utilisée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_seasons', function (Blueprint $table) {
            $table->unsignedBigInteger('competition_id')->primary();
            $table->string('name', 200);
            $table->string('category', 40); // 'Highlander Season' | '6v6 Season'
            $table->string('type', 40)->nullable();
            $table->string('game_mode', 8); // '9v9' | '6s'
            $table->string('season_label', 100)->nullable();
            $table->unsignedBigInteger('start_ts')->nullable();
            $table->unsignedBigInteger('end_ts')->nullable();
            $table->unsignedBigInteger('fetched_at')->nullable();

            $table->index('category');
        });

        Schema::create('player_season_matches', function (Blueprint $table) {
            $table->string('steamid', 32); // steamid3 (équivalent de players_info.steamid)
            $table->unsignedBigInteger('match_id');
            $table->unsignedBigInteger('competition_id');
            $table->unsignedBigInteger('team_id'); // l'équipe que le joueur représentait
            $table->unsignedBigInteger('opponent_team_id')->nullable();
            $table->string('game_mode', 8);
            $table->unsignedBigInteger('time')->nullable();
            $table->string('round', 120)->nullable();
            $table->integer('r1')->nullable();
            $table->integer('r2')->nullable();
            $table->boolean('team_is_clan1')->default(1);
            $table->primary(['steamid', 'match_id']);

            $table->index('team_id');
            $table->index('competition_id');
            $table->index('match_id');
        });

        Schema::create('official_match_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary();
            $table->unsignedBigInteger('match_id');
            $table->string('category', 8); // '9v9' | '6s'
            $table->unsignedBigInteger('red_team_id')->nullable();
            $table->unsignedBigInteger('blue_team_id')->nullable();
            $table->string('source', 16)->default('auto'); // 'auto' | 'manual'
            $table->string('added_by', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('match_id');
            $table->index('red_team_id');
            $table->index('blue_team_id');
        });

        // Dernière tentative de scrape d'une page ETF2L pour un match : évite de
        // re-scraper à chaque clic les matchs qui n'ont tout simplement pas de log.
        Schema::create('official_match_scrape', function (Blueprint $table) {
            $table->unsignedBigInteger('match_id')->primary();
            $table->unsignedBigInteger('checked_at');
        });

        Schema::create('stat_lineup', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id');
            $table->string('steamid', 32);
            $table->boolean('visible')->default(1);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['team_id', 'steamid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_match_scrape');
        Schema::dropIfExists('stat_lineup');
        Schema::dropIfExists('official_match_logs');
        Schema::dropIfExists('player_season_matches');
        Schema::dropIfExists('official_seasons');
    }
};
