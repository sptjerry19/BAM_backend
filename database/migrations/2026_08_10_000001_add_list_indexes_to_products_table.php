<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Speeds category filters; composite helps ORDER BY id with WHERE category.
            $table->index('category', 'products_category_index');
            $table->index(['category', 'id'], 'products_category_id_index');
            $table->index('sold_count', 'products_sold_count_index');
            $table->index('featured', 'products_featured_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_category_index');
            $table->dropIndex('products_category_id_index');
            $table->dropIndex('products_sold_count_index');
            $table->dropIndex('products_featured_index');
        });
    }
};
