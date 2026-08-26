<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le casque au matériel renseigné volontairement par les joueurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players_info', function (Blueprint $table): void {
            $table->string('gear_headset', 100)->nullable()->after('gear_mouse');
        });
    }

    public function down(): void
    {
        Schema::table('players_info', function (Blueprint $table): void {
            $table->dropColumn('gear_headset');
        });
    }
};
