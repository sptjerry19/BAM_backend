<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('images')->nullable()->after('image');
        });

        // Backfill: move legacy single image into images[]
        $products = DB::table('products')->select('id', 'image')->get();
        foreach ($products as $product) {
            if (! $product->image) {
                continue;
            }

            DB::table('products')->where('id', $product->id)->update([
                'images' => json_encode([$product->image]),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('images');
        });
    }
};
