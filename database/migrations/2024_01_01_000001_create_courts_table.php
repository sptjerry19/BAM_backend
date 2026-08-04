<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address');
            $table->string('district');
            $table->unsignedInteger('price');
            $table->string('surfaces');
            $table->unsignedInteger('courts');
            $table->enum('status', ['open', 'busy', 'closed'])->default('open');
            $table->decimal('rating', 2, 1)->default(0);
            $table->string('image')->nullable();
            $table->decimal('x', 5, 2)->default(0);
            $table->decimal('y', 5, 2)->default(0);
            $table->string('phone')->nullable();
            $table->string('hours')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courts');
    }
};
