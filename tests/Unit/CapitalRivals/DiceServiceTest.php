<?php

namespace Tests\Unit\CapitalRivals;

use App\Services\CapitalRivals\DiceService;
use PHPUnit\Framework\TestCase;

class DiceServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        DiceService::$generator = null;
        parent::tearDown();
    }

    public function test_server_rolls_two_dice_in_range(): void
    {
        $dice = DiceService::roll(2, 6);
        $this->assertCount(2, $dice);
        foreach ($dice as $face) {
            $this->assertGreaterThanOrEqual(1, $face);
            $this->assertLessThanOrEqual(6, $face);
        }
    }

    public function test_scripted_generator_is_used(): void
    {
        DiceService::$generator = static fn () => 4;
        $this->assertSame([4, 4], DiceService::roll(2, 6));
    }
}
