<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cr_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('cr_rooms')->cascadeOnDelete();
            $table->unsignedTinyInteger('tile_index');
            $table->unsignedTinyInteger('owner_seat');
            $table->unsignedTinyInteger('level')->default(0);
            $table->timestamps();
            $table->unique(['room_id', 'tile_index']);
        });

        Schema::create('cr_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('cr_rooms')->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedBigInteger('from_player_id')->nullable();
            $table->unsignedBigInteger('to_player_id')->nullable();
            $table->unsignedInteger('amount')->default(0);
            $table->unsignedTinyInteger('tile_index')->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cr_transactions');
        Schema::dropIfExists('cr_properties');
    }
};
