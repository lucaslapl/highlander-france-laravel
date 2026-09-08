<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Équipes exlues des logs officiels et des overlays OBS.
 *
 * Certaines équipes ETF2L programmées ne sont pas réellement françaises
 * (ex. "Cock Riders") et polluent les statistiques. Une équipe blacklistée
 * n'apparaît plus dans les équipes sélectionnables et tous les logs officiels
 * auxquels elle a participé sont exclus des stats des overlays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_blacklist', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->primary();
            $table->string('reason', 255)->nullable();
            $table->string('added_by', 64)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_blacklist');
    }
};