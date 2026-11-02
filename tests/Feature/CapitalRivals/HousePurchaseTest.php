<?php

namespace Tests\Feature\CapitalRivals;

use App\Models\CapitalRivals\BoardProperty;
use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;
use App\Services\CapitalRivals\BoardDefinition;
use App\Support\CapitalRivals\MatchStatus;
use Tests\TestCase;

class HousePurchaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'house_purchase_test',
            'database.connections.house_purchase_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
        ]);
        foreach (['160000_create_capital_rivals_tables', '170000_create_capital_rivals_economy_tables'] as $migration) {
            (require database_path('migrations/2026_10_24_'.$migration.'.php'))->up();
        }
    }

    private function pendingRoom(string $code = 'HOUSE', int $cash = 1500, int $tile = 1): array
    {
        $definition = BoardDefinition::tiles()[$tile];
        $room = Room::query()->create([
            'code' => $code, 'status' => MatchStatus::IN_PROGRESS,
            'phase' => MatchStatus::RESOLVING_TILE, 'current_seat' => 0,
            'turn_deadline' => now()->addMinutes(5), 'settings' => BoardDefinition::settings(false),
            'state' => ['pending' => ['action' => 'buy', 'tile' => $tile, 'price' => $definition['price'], 'name' => $definition['name']]],
        ]);
        $owner = Player::query()->create(['room_id' => $room->id, 'seat' => 0, 'turn_order' => 0, 'name' => 'Lan', 'skin' => 'ruby', 'player_token' => $code.'-owner', 'cash' => $cash, 'position' => $tile]);
        Player::query()->create(['room_id' => $room->id, 'seat' => 1, 'turn_order' => 1, 'name' => 'Minh', 'skin' => 'ace', 'player_token' => $code.'-guest', 'cash' => 1500]);

        return [$room, $owner, ['X-Player-Token' => $code.'-owner']];
    }

    public function test_first_purchase_supports_three_levels_and_charges_server_prices(): void
    {
        foreach ([1 => 60, 2 => 90, 3 => 120] as $level => $price) {
            [$room, $owner, $headers] = $this->pendingRoom('HOUSE'.$level);
            $snapshot = $this->getJson('/api/games/cr/rooms/'.$room->code, $headers)->assertOk();
            $this->assertSame([['level' => 1, 'price' => 60], ['level' => 2, 'price' => 90], ['level' => 3, 'price' => 120]], $snapshot->json('data.you.purchase_options'));
            $this->postJson('/api/games/cr/rooms/'.$room->code.'/buy', ['level' => $level, 'price' => 1], $headers)->assertOk();
            $this->assertSame(1500 - $price, $owner->fresh()->cash);
            $this->assertSame($level, BoardProperty::query()->where('room_id', $room->id)->value('level'));
        }
    }

    public function test_initial_purchase_rejects_level_four_and_invalid_levels(): void
    {
        [$room, $owner, $headers] = $this->pendingRoom();
        foreach ([0, 4, 99, 1.5, 'invalid'] as $level) {
            $this->postJson('/api/games/cr/rooms/'.$room->code.'/buy', ['level' => $level], $headers)->assertStatus(422);
        }
        $this->assertSame(1500, $owner->fresh()->cash);
        $this->assertSame(0, BoardProperty::query()->count());
    }

    public function test_insufficient_cash_cannot_buy_higher_level(): void
    {
        [$room, $owner, $headers] = $this->pendingRoom(cash: 100);
        $this->postJson('/api/games/cr/rooms/'.$room->code.'/buy', ['level' => 3], $headers)->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_funds');
        $this->assertSame(100, $owner->fresh()->cash);
        $this->assertSame(0, BoardProperty::query()->count());
        $this->postJson('/api/games/cr/rooms/'.$room->code.'/buy', ['level' => 1], $headers)->assertOk();
        $this->assertSame(40, $owner->fresh()->cash);
    }

    public function test_level_four_is_available_only_through_upgrade_after_purchase(): void
    {
        [$room, $owner, $headers] = $this->pendingRoom();
        $room->state = ['pending' => null];
        $room->phase = MatchStatus::WAITING_FOR_ACTION;
        $room->save();
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 1, 'owner_seat' => 0, 'level' => 3]);
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 3, 'owner_seat' => 0, 'level' => 1]);
        $this->postJson('/api/games/cr/rooms/'.$room->code.'/upgrade', ['tile_index' => 1], $headers)->assertOk();
        $this->assertSame(4, BoardProperty::query()->where('tile_index', 1)->value('level'));
        $this->assertSame(1470, $owner->fresh()->cash);
        $this->postJson('/api/games/cr/rooms/'.$room->code.'/upgrade', ['tile_index' => 1], $headers)->assertStatus(422);
    }

    public function test_beach_remains_a_single_purchase_without_house_level(): void
    {
        [$room, $owner, $headers] = $this->pendingRoom(tile: 5);
        $this->postJson('/api/games/cr/rooms/'.$room->code.'/buy', ['level' => 3], $headers)->assertStatus(422);
        $this->postJson('/api/games/cr/rooms/'.$room->code.'/buy', ['level' => 1], $headers)->assertOk();
        $this->assertSame(1300, $owner->fresh()->cash);
        $this->assertSame(0, BoardProperty::query()->value('level'));
    }
}
