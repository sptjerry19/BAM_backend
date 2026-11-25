<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Runs the real Content Studio migration on an in-memory SQLite database, so
 * these tests never touch the MySQL used by the application.
 *
 * Hooked through Laravel's `setUp{TraitName}` convention instead of overriding
 * setUp(): a test class that defines its own setUp() would otherwise silently
 * skip this trait and run against the real database.
 */
trait UsesContentStudioDatabase
{
    protected function setUpUsesContentStudioDatabase(): void
    {
        config([
            'database.default' => 'cs_test',
            'database.connections.cs_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);
        DB::purge('cs_test');

        // Hard stop: never continue if we are not on the throw-away database.
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Content Studio tests must run on in-memory SQLite, not '.$connection->getDriverName());
        }

        (require database_path('migrations/2026_11_07_100000_create_content_studio_tables.php'))->up();
        (require database_path('migrations/2026_11_08_100000_content_studio_phase2.php'))->up();
        (require database_path('migrations/2026_11_09_100000_create_racket_specs_table.php'))->up();

        // EquipmentProvider reads the shop's `products` table. Minimal copy with the columns it uses.
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('brand')->default('Other');
            $t->string('category')->default('racket');
            $t->unsignedInteger('price')->default(0);
            $t->unsignedInteger('original_price')->nullable();
            $t->unsignedInteger('stock')->default(0);
            $t->text('description')->nullable();
            $t->boolean('featured')->default(false);
            $t->unsignedInteger('sold_count')->default(0);
            $t->timestamps();
        });
    }
}
