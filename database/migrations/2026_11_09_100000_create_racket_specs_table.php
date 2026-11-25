<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Specs read from manufacturers' own product pages. Every row keeps the page it came from. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('racket_specs', function (Blueprint $t) {
            $t->id();
            $t->string('brand', 32);
            $t->string('model_name', 190);
            $t->string('source_url', 500)->unique();
            $t->json('specs_json');
            $t->char('content_hash', 64);
            $t->timestamp('fetched_at');
            $t->timestamps();
            $t->index(['brand', 'model_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('racket_specs');
    }
};
