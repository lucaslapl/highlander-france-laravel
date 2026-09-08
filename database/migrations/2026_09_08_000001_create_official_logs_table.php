<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logs.tf des matchs officiels de ligue (ETF2L Highlander / 6v6, équipe de France).
 *
 * Chaque ligne associe un log logs.tf au match ETF2L concerné et attribue les
 * deux camps (Red/Blue) aux deux équipes ETF2L. Les stats des overlays sont
 * calculées exclusivement depuis cette table (jamais sur les amicals).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary();
            $table->string('category', 8); // '9v9' | '6s'
            $table->unsignedBigInteger('etf2l_match_id')->nullable();
            $table->unsignedBigInteger('scope_team_id')->nullable();
            $table->unsignedBigInteger('red_team_id')->nullable();
            $table->unsignedBigInteger('blue_team_id')->nullable();
            $table->string('source', 16)->default('auto'); // 'auto' | 'manual'
            $table->string('added_by', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('etf2l_match_id');
            $table->index('scope_team_id');
            $table->index('red_team_id');
            $table->index('blue_team_id');
            $table->index('category');
        });

        // Dernière vérification des logs d'un match ETF2L : permet de ne pas
        // re-scraper inlassablement les matchs qui n'ont simplement aucun log.
        Schema::table('etf2l_matches', function (Blueprint $table) {
            $table->unsignedBigInteger('logs_checked_at')->nullable()->after('map_results');
        });
    }

    public function down(): void
    {
        Schema::table('etf2l_matches', function (Blueprint $table) {
            $table->dropColumn('logs_checked_at');
        });

        Schema::dropIfExists('official_logs');
    }
};
