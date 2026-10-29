<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cr_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->unsignedBigInteger('host_player_id')->nullable();
            $table->string('status', 32);
            $table->string('phase', 32);
            $table->unsignedTinyInteger('current_seat')->nullable();
            $table->timestamp('turn_deadline')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->json('settings');
            $table->json('state')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cr_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('cr_rooms')->cascadeOnDelete();
            $table->unsignedTinyInteger('seat');
            $table->unsignedTinyInteger('turn_order')->default(0);
            $table->string('name', 40);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('player_token', 64)->unique();
            $table->string('skin', 16)->default('ace');
            $table->unsignedInteger('cash')->default(0);
            $table->unsignedTinyInteger('position')->default(0);
            $table->boolean('ready')->default(false);
            $table->boolean('bankrupt')->default(false);
            $table->timestamps();

            $table->unique(['room_id', 'seat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cr_players');
        Schema::dropIfExists('cr_rooms');
    }
};
