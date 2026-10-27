<?php

namespace Tests\Unit\CapitalRivals;

use App\Services\CapitalRivals\BoardDefinition;
use PHPUnit\Framework\TestCase;

class BoardDefinitionTest extends TestCase
{
    public function test_board_has_forty_tiles_with_required_mix(): void
    {
        $tiles = BoardDefinition::tiles();
        $this->assertCount(40, $tiles);
        $indexes = array_column($tiles, 'index');
        sort($indexes);
        $this->assertSame(range(0, 39), $indexes);

        $types = array_count_values(array_column($tiles, 'type'));
        $this->assertSame(22, $types['property'] ?? 0);
        $this->assertSame(4, $types['service'] ?? 0);
        $this->assertSame(4, $types['chance'] ?? 0);
        $this->assertSame(3, $types['tax'] ?? 0);
        $this->assertSame(1, $types['start'] ?? 0);
        $this->assertSame(1, $types['detention'] ?? 0);
        $this->assertSame(1, $types['go_to_detention'] ?? 0);
        $this->assertSame(4, $types['rest'] ?? 0);
    }
}
