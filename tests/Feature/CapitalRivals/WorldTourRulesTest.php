<?php

namespace Tests\Feature\CapitalRivals;

use App\Models\CapitalRivals\BoardProperty;
use App\Models\CapitalRivals\LedgerEntry;
use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;
use App\Services\CapitalRivals\BoardDefinition;
use App\Services\CapitalRivals\ChanceDeck;
use App\Services\CapitalRivals\DiceService;
use App\Support\CapitalRivals\MatchStatus;
use Tests\TestCase;

class WorldTourRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'world_rules_test', 'database.connections.world_rules_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'cache.default' => 'array']);
        foreach (['160000_create_capital_rivals_tables', '170000_create_capital_rivals_economy_tables'] as $migration) {
            (require database_path('migrations/2026_10_24_'.$migration.'.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        DiceService::$generator = null;
        ChanceDeck::$nextId = null;
        parent::tearDown();
    }

    private function game(int $position = 0): array
    {
        $room = Room::query()->create([
            'code' => 'WORLD', 'status' => MatchStatus::IN_PROGRESS, 'phase' => MatchStatus::WAITING_FOR_ACTION,
            'current_seat' => 0, 'turn_deadline' => now()->addMinutes(5), 'settings' => BoardDefinition::settings(),
            'state' => ['pending' => null, 'pot' => 200000, 'festivals' => [], 'player_stats' => [0 => ['laps' => 0, 'gold' => 15, 'island_turns' => 0], 1 => ['laps' => 0, 'gold' => 15, 'island_turns' => 0]]],
        ]);
        $player = Player::query()->create(['room_id' => $room->id, 'seat' => 0, 'turn_order' => 0, 'name' => 'Lan', 'player_token' => 'owner', 'cash' => 1400000, 'position' => $position]);
        Player::query()->create(['room_id' => $room->id, 'seat' => 1, 'turn_order' => 1, 'name' => 'Minh', 'player_token' => 'guest', 'cash' => 1400000]);

        return [$room, $player];
    }

    private function action(string $action, array $data = [], string $token = 'owner')
    {
        return $this->postJson('/api/games/cr/rooms/WORLD/action', ['action' => $action, ...$data], ['X-Player-Token' => $token]);
    }

    private function pending(Room $room, string $action, int $index): void
    {
        $tile = $room->settings['tiles'][$index];
        $state = $room->fresh()->state;
        $state['pending'] = ['action' => $action, 'tile' => $index, 'name' => $tile['name'], 'price' => $tile['price'] ?? 0];
        $room->state = $state;
        $room->phase = MatchStatus::RESOLVING_TILE;
        $room->save();
        Player::query()->where('player_token', 'owner')->update(['position' => $index]);
    }

    private function roll(int $value = 1): void
    {
        DiceService::$generator = static fn () => $value;
        $this->postJson('/api/games/cr/rooms/WORLD/roll', [], ['X-Player-Token' => 'owner'])->assertOk();
    }

    public function test_equal_virtual_stakes_and_starting_festivals_are_created(): void
    {
        $create = $this->postJson('/api/games/cr/rooms', ['name' => 'Lan', 'entry_fee' => 200000, 'winnings_target' => 4000000])->assertCreated();
        $code = $create->json('data.room.code');
        $host = $create->json('data.you.player_token');
        $guest = $this->postJson('/api/games/cr/rooms/'.$code.'/join', ['name' => 'Minh'])->assertOk()->json('data.you.player_token');
        $this->postJson('/api/games/cr/rooms/'.$code.'/ready', ['ready' => true], ['X-Player-Token' => $guest])->assertOk();
        $start = $this->postJson('/api/games/cr/rooms/'.$code.'/start', [], ['X-Player-Token' => $host])->assertOk();
        $start->assertJsonPath('data.room.pot', 400000)->assertJsonPath('data.room.rules_version', 2);
        foreach ($start->json('data.players') as $p) {
            $this->assertSame(1300000, $p['cash']);
            $this->assertSame(15, $p['gold']);
            $this->assertSame(0, $p['laps']);
        }
        $this->assertCount(2, $start->json('data.state.festivals'));
        $this->assertSame(400000, (int) LedgerEntry::query()->where('type', 'stake')->sum('amount'));
    }

    public function test_reroll_costs_gold_and_does_not_move_until_confirmed(): void
    {
        [$room, $player] = $this->game();
        $this->roll();
        $this->assertSame(0, $player->fresh()->position);
        DiceService::$generator = static fn () => 2;
        $this->action('reroll')->assertOk()->assertJsonPath('data.state.dice', [2, 2]);
        $this->assertSame(12, $room->fresh()->state['player_stats'][0]['gold']);
        $this->assertSame(0, $player->fresh()->position);
        $this->action('confirm_roll')->assertOk()->assertJsonPath('data.state.pending.kind', 'tax');
        $this->assertSame(4, $player->fresh()->position);
        $this->action('confirm_roll')->assertStatus(422);
    }

    public function test_reroll_rejects_insufficient_gold_without_changing_dice(): void
    {
        [$room] = $this->game();
        $this->roll();
        $state = $room->fresh()->state;
        $state['player_stats'][0]['gold'] = 2;
        $room->state = $state;
        $room->save();
        $this->action('reroll')->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_gold');
        $this->assertSame([1, 1], $room->fresh()->state['dice']);
        $this->assertSame(2, $room->fresh()->state['player_stats'][0]['gold']);
    }

    public function test_passing_start_awards_300000_and_unlocks_level_three(): void
    {
        [$room, $player] = $this->game(31);
        $this->roll();
        $result = $this->action('confirm_roll')->assertOk();
        $this->assertSame(1700000, $player->fresh()->cash);
        $this->assertSame(1, $room->fresh()->state['player_stats'][0]['laps']);
        $this->assertCount(3, $result->json('data.you.purchase_options'));
        $result->assertJsonPath('data.you.max_initial_level', 3);
    }

    public function test_first_lap_blocks_level_three_but_allows_level_two(): void
    {
        [$room, $player] = $this->game();
        $this->pending($room, 'buy', 1);
        $this->getJson('/api/games/cr/rooms/WORLD', ['X-Player-Token' => 'owner'])->assertOk()->assertJsonPath('data.you.max_initial_level', 2);
        $this->postJson('/api/games/cr/rooms/WORLD/buy', ['level' => 3], ['X-Player-Token' => 'owner'])->assertStatus(422);
        $this->postJson('/api/games/cr/rooms/WORLD/buy', ['level' => 2], ['X-Player-Token' => 'owner'])->assertOk();
        $this->assertSame(1310000, $player->fresh()->cash);
        $this->assertSame(2, BoardProperty::query()->value('level'));
    }

    public function test_island_loses_three_turns_or_can_be_paid_to_exit(): void
    {
        [$room, $player] = $this->game(6);
        $this->roll();
        $this->action('confirm_roll')->assertOk();
        $this->assertSame(3, $room->fresh()->state['player_stats'][0]['island_turns']);
        $this->postJson('/api/games/cr/rooms/WORLD/end-turn', [], ['X-Player-Token' => 'guest'])->assertOk();
        $this->postJson('/api/games/cr/rooms/WORLD/roll', [], ['X-Player-Token' => 'owner'])->assertStatus(422);
        $this->action('wait_island')->assertOk();
        $this->assertSame(2, $room->fresh()->state['player_stats'][0]['island_turns']);
        $this->postJson('/api/games/cr/rooms/WORLD/end-turn', [], ['X-Player-Token' => 'guest'])->assertOk();
        $this->action('escape_island')->assertOk()->assertJsonPath('data.you.can_roll', true);
        $this->assertSame(1200000, $player->fresh()->cash);
        $this->assertSame(0, $room->fresh()->state['player_stats'][0]['island_turns']);
    }

    public function test_tax_is_ten_percent_of_cash_land_and_buildings(): void
    {
        [$room] = $this->game(2);
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 1, 'owner_seat' => 0, 'level' => 2]);
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 5, 'owner_seat' => 0, 'level' => 0]);
        $this->roll();
        $this->action('confirm_roll')->assertOk()->assertJsonPath('data.state.pending.amount', 169000);
    }

    public function test_airport_travel_charges_fee_without_free_start_reward_or_lap(): void
    {
        [$room, $player] = $this->game(22);
        $this->roll();
        $this->action('confirm_roll')->assertOk()->assertJsonPath('data.state.pending.action', 'travel');
        $this->action('travel', ['tile_index' => 0])->assertOk();
        $this->assertSame(1350000, $player->fresh()->cash);
        $this->assertSame(0, $player->fresh()->position);
        $this->assertSame(0, $room->fresh()->state['player_stats'][0]['laps']);
    }

    public function test_championship_increases_rent_multiplier_and_festival_rent_is_charged(): void
    {
        [$room] = $this->game(14);
        $this->roll();
        $this->action('confirm_roll')->assertOk()->assertJsonPath('data.state.pending.action', 'festival');
        $this->action('festival', ['tile_index' => 6])->assertOk();
        $this->assertSame(2, $room->fresh()->state['festivals'][6]);
        $room->refresh();
        $room->current_seat = 0;
        $room->save();
        Player::query()->where('player_token', 'owner')->update(['position' => 4]);
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 6, 'owner_seat' => 1, 'level' => 2]);
        $this->roll();
        $this->action('confirm_roll')->assertOk()->assertJsonPath('data.state.pending.amount', 30000);
    }

    public function test_hotel_survives_earthquake_and_takeover_cards(): void
    {
        [$room] = $this->game();
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 6, 'owner_seat' => 1, 'level' => 4]);
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 7, 'owner_seat' => 1, 'level' => 3]);
        $this->pending($room, 'card', 2);
        ChanceDeck::$nextId = 'earthquake';
        $this->postJson('/api/games/cr/rooms/WORLD/card', [], ['X-Player-Token' => 'owner'])->assertOk();
        $this->assertSame(4, BoardProperty::query()->where('tile_index', 6)->value('level'));
        $this->assertSame(2, BoardProperty::query()->where('tile_index', 7)->value('level'));
        $room->refresh();
        $room->current_seat = 0;
        $room->save();
        $this->pending($room, 'card', 2);
        ChanceDeck::$nextId = 'takeover';
        $this->postJson('/api/games/cr/rooms/WORLD/card', [], ['X-Player-Token' => 'owner'])->assertOk();
        $this->assertSame(1, BoardProperty::query()->where('tile_index', 6)->value('owner_seat'));
        $this->assertSame(0, BoardProperty::query()->where('tile_index', 7)->value('owner_seat'));
    }

    public function test_all_monopoly_types_finish_match_and_pay_pool_once(): void
    {
        foreach (['side_monopoly' => [1, 3], 'triple_monopoly' => [6, 7, 9], 'resort_monopoly' => [5, 13, 21, 29]] as $reason => $indexes) {
            [$room, $player] = $this->game();
            $last = array_pop($indexes);
            foreach ($indexes as $index) {
                BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => $index, 'owner_seat' => 0, 'level' => 1]);
            }
            $this->pending($room, 'buy', $last);
            $price = $room->settings['tiles'][$last]['price'];
            $result = $this->postJson('/api/games/cr/rooms/WORLD/buy', ['level' => 1], ['X-Player-Token' => 'owner'])->assertOk();
            $result->assertJsonPath('data.room.win_reason', $reason)->assertJsonPath('data.room.status', MatchStatus::FINISHED)->assertJsonPath('data.room.pot', 0);
            $this->assertSame(1400000 - $price + 200000, $player->fresh()->cash);
            $this->assertSame(1, LedgerEntry::query()->where('type', 'winnings')->count());
            $this->postJson('/api/games/cr/rooms/WORLD/buy', ['level' => 1], ['X-Player-Token' => 'owner'])->assertStatus(422);
            // This loop deliberately resets only its isolated in-memory fixtures.
            LedgerEntry::query()->delete();
            BoardProperty::query()->delete();
            Player::query()->delete();
            Room::query()->delete();
        }
    }

    public function test_accept_winnings_requires_target_and_cannot_repeat_payout(): void
    {
        [$room, $player] = $this->game();
        $this->action('accept_winnings')->assertStatus(422);
        $player->cash = 3000000;
        $player->save();
        $this->action('accept_winnings')->assertOk()->assertJsonPath('data.room.win_reason', 'accept_winnings');
        $this->assertSame(3200000, $player->fresh()->cash);
        $this->action('accept_winnings')->assertStatus(422);
        $this->assertSame(1, LedgerEntry::query()->where('type', 'winnings')->count());
    }

    public function test_only_current_player_can_use_special_actions(): void
    {
        $this->game();
        $this->roll();
        $this->action('reroll', token: 'guest')->assertStatus(422)->assertJsonPath('errors.code', 'not_your_turn');
        $this->action('travel', ['tile_index' => 0])->assertStatus(422);
    }

    public function test_three_island_waits_release_player_on_the_following_turn(): void
    {
        [$room] = $this->game(8);
        $state = $room->state;
        $state['player_stats'][0]['island_turns'] = 3;
        $room->state = $state;
        $room->save();
        foreach ([2, 1, 0] as $remaining) {
            $this->action('wait_island')->assertOk();
            $this->assertSame($remaining, $room->fresh()->state['player_stats'][0]['island_turns']);
            $this->postJson('/api/games/cr/rooms/WORLD/end-turn', [], ['X-Player-Token' => 'guest'])->assertOk();
        }
        $this->roll();
        $this->action('confirm_roll')->assertOk();
    }

    public function test_level_three_requires_lap_and_hotel_requires_another_visit(): void
    {
        [$room] = $this->game();
        $property = BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 6, 'owner_seat' => 0, 'level' => 2]);
        $this->pending($room, 'upgrade', 6);
        $upgrade = fn () => $this->postJson('/api/games/cr/rooms/WORLD/upgrade', ['tile_index' => 6], ['X-Player-Token' => 'owner']);
        $upgrade()->assertStatus(422);
        $state = $room->fresh()->state;
        $state['player_stats'][0]['laps'] = 1;
        $room->state = $state;
        $room->save();
        $upgrade()->assertOk();
        $this->assertSame(3, $property->fresh()->level);
        $upgrade()->assertStatus(422);
        $room->refresh();
        $room->current_seat = 0;
        $room->save();
        $this->pending($room, 'upgrade', 6);
        $upgrade()->assertOk();
        $this->assertSame(4, $property->fresh()->level);
    }

    public function test_paid_takeover_transfers_cash_and_ownership_after_rent(): void
    {
        [$room, $player] = $this->game(4);
        $property = BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 6, 'owner_seat' => 1, 'level' => 2]);
        $this->roll();
        $rent = $this->action('confirm_roll')->assertOk()->json('data.state.pending.amount');
        $pay = $this->postJson('/api/games/cr/rooms/WORLD/pay', [], ['X-Player-Token' => 'owner'])->assertOk();
        $pay->assertJsonPath('data.state.pending.action', 'takeover');
        $price = $pay->json('data.state.pending.price');
        $this->action('takeover')->assertOk();
        $this->assertSame(0, $property->fresh()->owner_seat);
        $this->assertSame(1400000 - $rent - $price, $player->fresh()->cash);
        $this->assertSame(1400000 + $rent + $price, Player::query()->where('player_token', 'guest')->value('cash'));
        $this->action('takeover')->assertStatus(422);
    }

    public function test_bankruptcy_pays_survivor_once_and_pending_debt_blocks_accept_winnings(): void
    {
        [$room, $player] = $this->game(2);
        $player->cash = 3000000;
        $player->save();
        $this->roll();
        $this->action('confirm_roll')->assertOk();
        $this->action('accept_winnings')->assertStatus(422);
        $this->postJson('/api/games/cr/rooms/WORLD/bankrupt', [], ['X-Player-Token' => 'owner'])->assertOk()->assertJsonPath('data.room.win_reason', 'last_standing');
        $this->assertSame(1600000, Player::query()->where('player_token', 'guest')->value('cash'));
        $this->postJson('/api/games/cr/rooms/WORLD/bankrupt', [], ['X-Player-Token' => 'owner'])->assertStatus(422);
        $this->assertSame(1, LedgerEntry::query()->where('type', 'winnings')->count());
    }
}
