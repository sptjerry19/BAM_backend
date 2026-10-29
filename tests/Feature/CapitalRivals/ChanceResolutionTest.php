<?php

namespace Tests\Feature\CapitalRivals;

use App\Models\CapitalRivals\LedgerEntry;
use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;
use App\Services\CapitalRivals\BoardDefinition;
use App\Services\CapitalRivals\ChanceDeck;
use App\Services\CapitalRivals\DiceService;
use App\Support\CapitalRivals\MatchStatus;
use Tests\TestCase;

class ChanceResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'chance_test',
            'database.connections.chance_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
        ]);
        foreach (['160000_create_capital_rivals_tables', '170000_create_capital_rivals_economy_tables'] as $migration) {
            (require database_path('migrations/2026_10_24_'.$migration.'.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        ChanceDeck::$nextId = null;
        DiceService::$generator = null;
        parent::tearDown();
    }

    private function chanceRoom(string $code, int $cash = 1500): array
    {
        $room = Room::query()->create([
            'code' => $code, 'status' => MatchStatus::IN_PROGRESS,
            'phase' => MatchStatus::RESOLVING_TILE, 'current_seat' => 0,
            'turn_deadline' => now()->addMinutes(5),
            'settings' => [...BoardDefinition::settings(false), 'start_reward' => 350],
            'state' => ['pending' => ['action' => 'card', 'tile' => 2]],
        ]);
        $player = Player::query()->create(['room_id' => $room->id, 'seat' => 0, 'turn_order' => 0, 'name' => 'Lan', 'player_token' => $code.'-owner', 'cash' => $cash, 'position' => 2]);
        Player::query()->create(['room_id' => $room->id, 'seat' => 1, 'turn_order' => 1, 'name' => 'Minh', 'player_token' => $code.'-guest', 'cash' => 1500]);

        return [$room, $player, ['X-Player-Token' => $code.'-owner']];
    }

    public function test_all_six_card_effects_are_applied_and_report_actual_results(): void
    {
        $cases = [
            'grant_50' => [1550, 2, 50], 'grant_100' => [1600, 2, 100],
            'levy_50' => [1450, 2, -50], 'levy_75' => [1425, 2, -75],
            'recall_gate' => [1500, 8, 0], 'harbor_gate' => [1850, 0, 350],
        ];
        foreach ($cases as $id => [$cash, $position, $delta]) {
            [$room, $player, $headers] = $this->chanceRoom('C'.count(Room::all()));
            ChanceDeck::$nextId = $id;
            $result = $this->postJson('/api/games/cr/rooms/'.$room->code.'/card', [], $headers)->assertOk();
            $this->assertSame($cash, $player->fresh()->cash);
            $this->assertSame($position, $player->fresh()->position);
            $result->assertJsonPath('data.state.last_card.id', $id)
                ->assertJsonPath('data.state.last_card.cash_change', $delta)
                ->assertJsonPath('data.state.last_card.position_after', $position)
                ->assertJsonPath('data.state.last_card.player_name', 'Lan')
                ->assertJsonPath('data.state.pending', null);
            $this->assertNotEmpty($result->json('data.state.last_card.draw_id'));
            $this->getJson('/api/games/cr/rooms/'.$room->code)->assertOk()
                ->assertJsonPath('data.state.last_card.draw_id', $result->json('data.state.last_card.draw_id'));
        }
    }

    public function test_duplicate_draw_cannot_apply_reward_twice(): void
    {
        [$room, $player, $headers] = $this->chanceRoom('ONCE');
        ChanceDeck::$nextId = 'grant_100';
        $this->postJson('/api/games/cr/rooms/ONCE/card', [], $headers)->assertOk();
        $count = LedgerEntry::query()->count();
        $this->postJson('/api/games/cr/rooms/ONCE/card', [], $headers)->assertStatus(422);
        $this->assertSame(1600, $player->fresh()->cash);
        $this->assertSame($count, LedgerEntry::query()->count());
    }

    public function test_debit_card_reports_actual_payment_and_bankruptcy(): void
    {
        [$room, $player, $headers] = $this->chanceRoom('BROKE', 30);
        ChanceDeck::$nextId = 'levy_75';
        $this->postJson('/api/games/cr/rooms/BROKE/card', [], $headers)->assertOk()
            ->assertJsonPath('data.state.last_card.cash_change', -30)
            ->assertJsonPath('data.state.last_card.bankrupt', true)
            ->assertJsonPath('data.room.status', MatchStatus::FINISHED);
        $this->assertSame(0, $player->fresh()->cash);
    }

    public function test_expired_chance_turn_is_resolved_once_by_server(): void
    {
        [$room, $player, $headers] = $this->chanceRoom('EXPIRED');
        $room->turn_deadline = now()->subSecond();
        $room->save();
        ChanceDeck::$nextId = 'grant_50';
        $first = $this->getJson('/api/games/cr/rooms/EXPIRED', $headers)->assertOk();
        $first->assertJsonPath('data.state.last_card.id', 'grant_50');
        $this->getJson('/api/games/cr/rooms/EXPIRED', $headers)->assertOk()
            ->assertJsonPath('data.state.last_card.draw_id', $first->json('data.state.last_card.draw_id'));
        $this->assertSame(1550, $player->fresh()->cash);
    }

    public function test_dice_landing_on_chance_enables_draw_without_applying_a_card_early(): void
    {
        [$room, $player, $headers] = $this->chanceRoom('LAND');
        $room->state = ['pending' => null];
        $room->phase = MatchStatus::WAITING_FOR_ACTION;
        $room->save();
        $player->position = 0;
        $player->save();
        DiceService::$generator = static fn () => 1;
        $this->postJson('/api/games/cr/rooms/LAND/roll', [], $headers)->assertOk()
            ->assertJsonPath('data.state.dice', [1, 1])
            ->assertJsonPath('data.state.pending.action', 'card')
            ->assertJsonPath('data.you.can_draw', true);
        $this->assertSame(1500, $player->fresh()->cash);
        $this->assertSame(2, $player->fresh()->position);
    }
}
