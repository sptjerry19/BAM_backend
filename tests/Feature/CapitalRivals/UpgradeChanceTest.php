<?php

namespace Tests\Feature\CapitalRivals;

use App\Models\CapitalRivals\BoardProperty;
use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;
use App\Services\CapitalRivals\ChanceDeck;
use App\Services\CapitalRivals\DiceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesCapitalRivalsMatch;
use Tests\TestCase;

class UpgradeChanceTest extends TestCase
{
    use CreatesCapitalRivalsMatch;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        DiceService::$generator = null;
        ChanceDeck::$nextId = null;
        parent::tearDown();
    }

    public function test_upgrade_requires_group_then_raises_level(): void
    {
        [$code, $first] = $this->startedPair();
        $room = Room::query()->where('code', $code)->first();
        $seat = (int) Player::query()->where('player_token', $first)->value('seat');
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 1, 'owner_seat' => $seat, 'level' => 0]);
        $this->postJson("/api/games/cr/rooms/{$code}/upgrade", ['tile_index' => 1], ['X-Player-Token' => $first])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'invalid_state');

        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 3, 'owner_seat' => $seat, 'level' => 0]);
        $up = $this->postJson("/api/games/cr/rooms/{$code}/upgrade", ['tile_index' => 1], ['X-Player-Token' => $first]);
        $up->assertOk();
        $this->assertSame(1, collect($up->json('data.properties'))->firstWhere('tile_index', 1)['level']);
        $cash = collect($up->json('data.players'))->firstWhere('seat', $seat)['cash'];
        $this->assertSame(1470, $cash);
    }

    public function test_chance_card_credits_and_advances(): void
    {
        $this->scriptDice([1, 1]);
        ChanceDeck::$nextId = 'grant_50';
        [$code, $first] = $this->startedPair();
        $roll = $this->postJson("/api/games/cr/rooms/{$code}/roll", [], ['X-Player-Token' => $first]);
        $roll->assertOk();
        $this->assertSame('card', $roll->json('data.state.pending.action'));
        $card = $this->postJson("/api/games/cr/rooms/{$code}/card", [], ['X-Player-Token' => $first]);
        $card->assertOk();
        $this->assertSame('grant_50', $card->json('data.state.last_card.id'));
        $player = collect($card->json('data.players'))->firstWhere('seat', $card->json('data.you.seat'));
        $this->assertSame(1550, $player['cash']);
        $this->assertNull($card->json('data.state.pending'));
    }
}
