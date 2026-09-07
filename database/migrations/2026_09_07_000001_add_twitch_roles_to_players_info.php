<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players_info', function (Blueprint $table): void {
            $table->boolean('is_caster')->default(0)->after('is_moderator');
            $table->boolean('is_producer')->default(0)->after('is_caster');
        });
    }

    public function down(): void
    {
        Schema::table('players_info', function (Blueprint $table): void {
            $table->dropColumn(['is_caster', 'is_producer']);
        });
    }
};
