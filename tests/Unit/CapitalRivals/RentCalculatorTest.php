<?php

namespace Tests\Unit\CapitalRivals;

use App\Models\CapitalRivals\BoardProperty;
use App\Services\CapitalRivals\BoardDefinition;
use App\Services\CapitalRivals\RentCalculator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class RentCalculatorTest extends TestCase
{
    public function test_base_property_rent_is_level_zero(): void
    {
        $tiles = BoardDefinition::tiles();
        $tile = $tiles[1];
        $owned = new BoardProperty(['tile_index' => 1, 'owner_seat' => 0, 'level' => 0]);
        $amount = RentCalculator::amount($tile, 0, new Collection([$owned]), $tiles);
        $this->assertSame(4, $amount);
    }

    public function test_level_one_uses_second_rent_band(): void
    {
        $tiles = BoardDefinition::tiles();
        $tile = $tiles[1];
        $owned = new BoardProperty(['tile_index' => 1, 'owner_seat' => 0, 'level' => 1]);
        $amount = RentCalculator::amount($tile, 0, new Collection([$owned]), $tiles);
        $this->assertSame(10, $amount);
    }
}
