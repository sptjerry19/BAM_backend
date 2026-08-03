<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_equipments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('member_id')->index();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->enum('category', ['racket', 'shoes', 'shirt', 'shorts', 'bag', 'grip', 'other', 'shuttlecock', 'string']);
            $table->unsignedInteger('price')->nullable();
            $table->text('notes')->nullable();
            $table->longText('image')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_equipments');
    }
};
