<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('france_national_players', function (Blueprint $table) {
            $table->string('steamid');
            $table->string('steamid64', 32)->nullable();
            $table->unsignedBigInteger('etf2l_player_id')->nullable();
            $table->string('mode', 16);
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('fetched_at')->nullable();

            $table->primary(['steamid', 'mode']);
            $table->index('steamid64');
            $table->index('mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('france_national_players');
    }
};
