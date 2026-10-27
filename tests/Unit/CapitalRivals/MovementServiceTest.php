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
}
