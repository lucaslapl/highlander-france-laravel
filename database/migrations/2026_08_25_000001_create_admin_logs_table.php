<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit structuré des scripts CRON, webhooks serveurs/bot Discord
 * et tests API du panel admin (remplace l'ancien fichier plat cron_debug.log).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_logs', function (Blueprint $table) {
            $table->id();
            $table->string('script', 64);
            $table->string('status', 16); // started / success / failed / ignored
            $table->text('message')->nullable();
            $table->string('context', 16); // cli / web / webhook
            $table->string('user_steamid', 32)->nullable();
            $table->string('user_name', 128)->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();

            $table->index(['script', 'started_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
    }
};
