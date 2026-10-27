<?php

namespace Tests\Unit\CapitalRivals;

use App\Models\CapitalRivals\BoardProperty;
use App\Services\CapitalRivals\BoardDefinition;
use App\Services\CapitalRivals\PropertyUpgrade;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class PropertyUpgradeTest extends TestCase
{
    public function test_cost_is_half_price(): void
    {
        $this->assertSame(30, PropertyUpgrade::cost(['price' => 60], ['upgrade_cost_ratio' => 0.5]));
    }

    public function test_owns_group_requires_every_tile(): void
    {
        $tiles = BoardDefinition::tiles();
        $partial = new Collection([
            new BoardProperty(['tile_index' => 1, 'owner_seat' => 0, 'level' => 0]),
        ]);
        $this->assertFalse(PropertyUpgrade::ownsGroup(0, 'umber', $partial, $tiles));

        $full = new Collection([
            new BoardProperty(['tile_index' => 1, 'owner_seat' => 0, 'level' => 0]),
            new BoardProperty(['tile_index' => 3, 'owner_seat' => 0, 'level' => 0]),
        ]);
        $this->assertTrue(PropertyUpgrade::ownsGroup(0, 'umber', $full, $tiles));
    }
}
