<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Informations de profil renseignées volontairement par les joueurs :
 * liens (ETF2L, RGL, logs.tf, Twitch, X, YouTube, Discord) et infos perso
 * (date de naissance, matériel). Tous les champs sont facultatifs et
 * modifiables à volonté (contrairement au pseudo / pays).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players_info', function (Blueprint $table): void {
            // Liens externes.
            $table->string('etf2l_url')->nullable();
            $table->string('rgl_url')->nullable();
            $table->string('logstf_url')->nullable();
            $table->string('twitch_url')->nullable();
            $table->string('x_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('discord_tag', 64)->nullable();

            // Infos personnelles.
            $table->date('birthdate')->nullable();
            $table->string('gear_keyboard', 100)->nullable();
            $table->string('gear_mouse', 100)->nullable();
            $table->string('gear_monitor', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players_info', function (Blueprint $table): void {
            $table->dropColumn([
                'etf2l_url',
                'rgl_url',
                'logstf_url',
                'twitch_url',
                'x_url',
                'youtube_url',
                'discord_tag',
                'birthdate',
                'gear_keyboard',
                'gear_mouse',
                'gear_monitor',
            ]);
        });
    }
};
