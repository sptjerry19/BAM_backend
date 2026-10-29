<?php

namespace Tests\Unit\CapitalRivals;

use App\Services\CapitalRivals\MovementService;
use PHPUnit\Framework\TestCase;

class MovementServiceTest extends TestCase
{
    public function test_wraps_and_counts_start_laps(): void
    {
        $this->assertSame(['position' => 5, 'laps' => 0], MovementService::advance(3, 2));
        $this->assertSame(['position' => 0, 'laps' => 1], MovementService::advance(38, 2));
        $this->assertSame(['position' => 1, 'laps' => 1], MovementService::advance(39, 2));
    }

    public function test_nine_by_nine_board_wraps_after_thirty_two_cells(): void
    {
        $this->assertSame(['position' => 0, 'laps' => 1], MovementService::advance(30, 2, 32));
        $this->assertSame(['position' => 1, 'laps' => 1], MovementService::advance(31, 2, 32));
        $this->assertSame(['position' => 8, 'laps' => 0], MovementService::advance(6, 2, 32));
    }
}
