<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Équipes françaises « gérées » (encadré sidebar + pages équipes).
 *
 * managed_teams : une ligne par équipe mise en avant, rattachée à une équipe
 * ETF2L (etf2l_team_id) dont le roster est récupéré via l'API. Le roster vit
 * dans managed_team_members (source de vérité pour le site), alimenté par
 * l'import ETF2L (source = etf2l) ou ajouté à la main par l'admin / le leader
 * (source = manual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_teams', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name', 255);
            $table->string('tag', 64)->nullable();
            $table->string('country', 64)->nullable();
            $table->unsignedBigInteger('etf2l_team_id')->index();
            $table->string('division', 16)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('slogan', 160)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(0);
            $table->timestamps();
        });

        Schema::create('managed_team_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('steamid64', 17);
            $table->unsignedBigInteger('etf2l_player_id')->nullable()->index();
            $table->string('steam_name', 255)->nullable();
            $table->string('country', 64)->nullable();
            $table->string('class', 32)->nullable();
            $table->string('status', 16)->default('starter');
            $table->boolean('is_leader')->default(0);
            $table->string('source', 16)->default('manual');
            $table->timestamps();

            $table->unique(['team_id', 'steamid64']);
            $table->index('steamid64');
            $table->foreign('team_id')->references('id')->on('managed_teams')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_team_members');
        Schema::dropIfExists('managed_teams');
    }
};
