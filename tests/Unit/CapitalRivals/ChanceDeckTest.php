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

    public function test_shuffled_deck_draws_every_card_once_before_reshuffling(): void
    {
        $state = [];
        $drawn = [];
        foreach (range(1, count(ChanceDeck::cards())) as $_) {
            $drawn[] = ChanceDeck::draw($state)['id'];
        }
        sort($drawn);
        $expected = array_keys(ChanceDeck::cards());
        sort($expected);
        $this->assertSame($expected, $drawn);
        $this->assertSame([], $state['chance_draw']);
        $this->assertArrayHasKey(ChanceDeck::draw($state)['id'], ChanceDeck::cards());
    }
}
