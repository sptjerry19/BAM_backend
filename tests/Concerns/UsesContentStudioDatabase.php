<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
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
    }
}
