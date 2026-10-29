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

    public function test_level_two_uses_second_rent_band(): void
    {
        $tiles = BoardDefinition::tiles();
        $tile = $tiles[1];
        $owned = new BoardProperty(['tile_index' => 1, 'owner_seat' => 0, 'level' => 2]);
        $amount = RentCalculator::amount($tile, 0, new Collection([$owned]), $tiles);
        $this->assertSame(10, $amount);
    }

    public function test_house_rent_increases_across_all_four_levels(): void
    {
        $tiles = BoardDefinition::tiles();
        foreach ([1 => 4, 2 => 10, 3 => 20, 4 => 40] as $level => $rent) {
            $owned = new BoardProperty(['tile_index' => 1, 'owner_seat' => 0, 'level' => $level]);
            $this->assertSame($rent, RentCalculator::amount($tiles[1], 0, new Collection([$owned]), $tiles));
        }
    }
}
