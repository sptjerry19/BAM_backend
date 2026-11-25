<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Studio phase 2. Only touches tables created by Content Studio itself
 * (content_*) and creates one new table; no bookings / members / products / news / social table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_sources', function (Blueprint $t) {
            $t->string('title', 500)->nullable();
            $t->text('summary')->nullable();
            // official | press : decides whether a news source may drive a post
            $t->string('trust', 20)->nullable();
        });

        Schema::table('content_plans', function (Blueprint $t) {
            // [{slot, reason}] for slots that were not produced, with the real reason
            $t->json('shortfalls_json')->nullable();
            $t->string('prompt_version', 64)->nullable();
        });

        Schema::create('content_item_products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            // Deliberately no FK: products is owned by the shop module and may be re-imported.
            $t->unsignedBigInteger('product_id');
            $t->string('role', 20)->default('subject'); // subject | compare
            $t->json('facts_snapshot_json')->nullable();
            // {price, visible, synced_at}: what the post was allowed to say about price
            $t->json('price_snapshot_json')->nullable();
            $t->timestamps();
            $t->unique(['content_item_id', 'product_id']);
            $t->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_products');

        Schema::table('content_plans', function (Blueprint $t) {
            $t->dropColumn(['shortfalls_json', 'prompt_version']);
        });
        Schema::table('content_sources', function (Blueprint $t) {
            $t->dropColumn(['title', 'summary', 'trust']);
        });
    }
};
