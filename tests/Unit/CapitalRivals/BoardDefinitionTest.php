<?php

namespace Tests\Unit\CapitalRivals;

use App\Services\CapitalRivals\BoardDefinition;
use PHPUnit\Framework\TestCase;

class BoardDefinitionTest extends TestCase
{
    public function test_nine_by_nine_board_has_thirty_two_tiles_with_required_mix(): void
    {
        $tiles = BoardDefinition::tiles();
        $this->assertCount(32, $tiles);
        $indexes = array_column($tiles, 'index');
        sort($indexes);
        $this->assertSame(range(0, 31), $indexes);

        $types = array_count_values(array_column($tiles, 'type'));
        $this->assertSame(20, $types['property'] ?? 0);
        $this->assertSame(4, $types['service'] ?? 0);
        $this->assertSame(3, $types['chance'] ?? 0);
        $this->assertSame(1, $types['tax'] ?? 0);
        $this->assertSame(1, $types['start'] ?? 0);
        $this->assertSame(1, $types['detention'] ?? 0);
        $this->assertSame(0, $types['go_to_detention'] ?? 0);
        $this->assertSame(1, $types['championship'] ?? 0);
        $this->assertSame(1, $types['world_tour'] ?? 0);
        $this->assertSame(['start', 'island', 'championship', 'world_tour'], array_map(fn ($i) => $tiles[$i]['special'], [0, 8, 16, 24]));
        $this->assertSame([3, 11, 19, 27], array_column(array_values(array_filter($tiles, fn ($tile) => ($tile['special'] ?? '') === 'resort')), 'index'));
    }
}
