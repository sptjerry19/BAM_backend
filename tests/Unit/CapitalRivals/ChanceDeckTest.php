<?php

namespace Tests\Unit\CapitalRivals;

use App\Services\CapitalRivals\ChanceDeck;
use PHPUnit\Framework\TestCase;

class ChanceDeckTest extends TestCase
{
    protected function tearDown(): void
    {
        ChanceDeck::$nextId = null;
        parent::tearDown();
    }

    public function test_forced_draw_uses_next_id(): void
    {
        ChanceDeck::$nextId = 'grant_50';
        $state = ['chance_draw' => []];
        $card = ChanceDeck::draw($state);
        $this->assertSame('grant_50', $card['id']);
        $this->assertSame('credit', $card['kind']);
        $this->assertSame(50, $card['amount']);
        $this->assertNull(ChanceDeck::$nextId);
    }

    public function test_cards_are_original_dispatch_set(): void
    {
        $this->assertCount(6, ChanceDeck::cards());
        $this->assertArrayHasKey('harbor_gate', ChanceDeck::cards());
    }
}
