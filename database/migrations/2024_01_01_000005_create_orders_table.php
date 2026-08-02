<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('member_id')->nullable();
            $table->unsignedBigInteger('total');
            $table->enum('status', ['pending', 'paid', 'shipped'])->default('pending');
            $table->json('items');
            $table->timestamps();

            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
