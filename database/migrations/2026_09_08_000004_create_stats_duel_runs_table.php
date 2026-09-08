<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stats_duel_runs', function (Blueprint $table) {
            $table->id();
            $table->integer('t1')->default(0);
            $table->integer('t2')->default(0);
            $table->string('mode1', 8)->nullable();
            $table->string('mode2', 8)->nullable();
            $table->integer('status')->default(0)->comment('0 pending, 1 running, 2 done, 3 error');
            $table->unsignedTinyInteger('progress')->default(0)->comment('0-100');
            $table->string('message', 255)->nullable();
            $table->longText('result')->nullable()->comment('JSON teamA/teamB');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stats_duel_runs');
    }
};