<?php

namespace Tests\Feature\CapitalRivals;

use App\Models\CapitalRivals\BoardProperty;
use App\Models\CapitalRivals\LedgerEntry;
use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;
use App\Services\CapitalRivals\RoomService;
use App\Support\CapitalRivals\MatchStatus;
use Tests\TestCase;

class BoardResizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Explicit isolated database: these tests never touch the app database.
        config([
            'database.default' => 'board_resize_test',
            'database.connections.board_resize_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        foreach (['160000_create_capital_rivals_tables', '170000_create_capital_rivals_economy_tables'] as $migration) {
            (require database_path('migrations/2026_10_24_'.$migration.'.php'))->up();
        }
    }

    private function legacyRoom(int $pendingTile): Room
    {
        $tiles = [];
        foreach (range(0, 39) as $i) {
            $tiles[] = ['index' => $i, 'type' => 'property', 'name' => 'Địa danh '.$i, 'group' => 'sky', 'price' => 100, 'rent' => [5, 10, 20]];
        }
        foreach ([0 => 'start', 10 => 'detention', 20 => 'rest', 30 => 'go_to_detention', 18 => 'rest', 28 => 'rest', 34 => 'rest', 2 => 'chance', 7 => 'chance', 22 => 'chance', 36 => 'chance', 4 => 'tax', 12 => 'tax', 38 => 'tax', 5 => 'service', 15 => 'service', 25 => 'service', 35 => 'service'] as $i => $type) {
            $tiles[$i]['type'] = $type;
        }

        return Room::query()->create([
            'code' => 'RESIZE', 'status' => MatchStatus::IN_PROGRESS,
            'phase' => MatchStatus::RESOLVING_TILE, 'current_seat' => 0,
            'settings' => ['tiles' => $tiles, 'upgrade_cost_ratio' => 0.5],
            'state' => ['pending' => ['action' => 'buy', 'tile' => $pendingTile, 'price' => 100]],
        ]);
    }

    public function test_rejoin_removes_eight_tiles_refunds_holdings_and_is_idempotent(): void
    {
        $room = $this->legacyRoom(9);
        $player = Player::query()->create(['room_id' => $room->id, 'seat' => 0, 'name' => 'Lan', 'player_token' => 'owner-token', 'cash' => 500, 'position' => 9]);
        foreach ([9 => 2, 8 => 1, 11 => 0] as $index => $level) {
            BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => $index, 'owner_seat' => 0, 'level' => $level]);
        }

        $service = app(RoomService::class);
        $snapshot = $service->join('RESIZE', null, null, 'owner-token');
        $this->assertCount(32, $snapshot['tiles']);
        $this->assertSame([7, 9], BoardProperty::query()->orderBy('tile_index')->pluck('tile_index')->all());
        $this->assertSame(700, $player->fresh()->cash);
        $this->assertSame(7, $player->fresh()->position);
        $this->assertNull($room->fresh()->state['pending']);
        $this->assertSame(MatchStatus::WAITING_FOR_ACTION, $room->fresh()->phase);
        $this->assertSame(['start', 'island', 'championship', 'world_tour'], array_map(fn ($i) => $snapshot['tiles'][$i]['special'], [0, 8, 16, 24]));
        $types = array_count_values(array_column($snapshot['tiles'], 'type'));
        $this->assertSame(4, $types['service']);
        $this->assertSame(3, $types['chance']);
        $this->assertSame(1, $types['tax']);
        $version = $room->fresh()->version;

        $service->join('RESIZE', null, null, 'owner-token');
        $this->assertSame(700, $player->fresh()->cash);
        $this->assertSame(1, LedgerEntry::query()->where('type', 'board_refund')->count());
        $this->assertSame($version, $room->fresh()->version);
    }

    public function test_pending_on_retained_tile_is_remapped(): void
    {
        $room = $this->legacyRoom(11);
        Player::query()->create(['room_id' => $room->id, 'seat' => 0, 'name' => 'Lan', 'player_token' => 'owner-token', 'position' => 11]);
        $snapshot = app(RoomService::class)->join('RESIZE', null, null, 'owner-token');
        $this->assertSame(9, $snapshot['state']['pending']['tile']);
        $this->assertSame(MatchStatus::RESOLVING_TILE, $room->fresh()->phase);
    }
}
