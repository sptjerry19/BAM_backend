<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE products MODIFY category ENUM('racket', 'shoes', 'shirt', 'shorts', 'shuttlecock', 'bag', 'grip', 'string', 'other') NOT NULL");
    }

    public function down(): void
    {
        DB::table('products')->where('category', 'other')->update(['category' => 'racket']);
        DB::statement("ALTER TABLE products MODIFY category ENUM('racket', 'shoes', 'shirt', 'shorts', 'shuttlecock', 'bag', 'grip', 'string') NOT NULL");
    }
};
