<?php

namespace Tests\Concerns;

trait UsesCapitalRivalsDatabase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'cr_test',
            'database.connections.cr_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
        ]);
        foreach (['160000_create_capital_rivals_tables', '170000_create_capital_rivals_economy_tables'] as $migration) {
            (require database_path('migrations/2026_10_24_'.$migration.'.php'))->up();
        }
    }
}
