<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format de jeu d'une équipe française gérée (managed_teams) : 9v9 par défaut
 * (Highlander), 6v6, ou tout autre format ajouté à config/hlfr.php
 * (clé « team_formats »). Suggéré à l'import ETF2L, ajustable par l'admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_teams', function (Blueprint $table) {
            $table->string('format', 16)->nullable()->default('9v9')->after('division');
        });
    }

    public function down(): void
    {
        Schema::table('managed_teams', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};
