<?php

namespace Tests\Feature\CapitalRivals;

use App\Models\CapitalRivals\BoardProperty;
use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;
use App\Services\CapitalRivals\BoardDefinition;
use App\Services\CapitalRivals\ChanceDeck;
use App\Services\CapitalRivals\DiceService;
use App\Services\CapitalRivals\GameRules;
use App\Support\CapitalRivals\MatchStatus;
use Tests\Concerns\UsesCapitalRivalsDatabase;
use Tests\TestCase;

class BusinessTourSpecTest extends TestCase
{
    use UsesCapitalRivalsDatabase;

    protected function tearDown(): void
    {
        DiceService::$generator = null;
        ChanceDeck::$nextId = null;
        parent::tearDown();
    }

    private function game(int $position = 0, int $cash = 2000000): array
    {
        $settings = BoardDefinition::settings();
        $room = Room::query()->create([
            'code' => 'SPEC01', 'status' => MatchStatus::IN_PROGRESS, 'phase' => 'PRE_ROLL',
            'current_seat' => 0, 'turn_deadline' => now()->addSeconds(15), 'started_at' => now(),
            'settings' => $settings,
            'state' => ['pending' => null, 'dice' => null, 'turn_id' => 1, 'turns_played' => 0, 'doubles_streak' => 0, 'extra_roll' => false,
                'festivals' => [], 'championship' => null, 'shields' => [], 'blackouts' => [], 'pending_debt' => null, 'eliminated' => [],
                'match_deadline' => now()->addMinutes(30)->toIso8601String(),
                'player_stats' => [0 => ['laps' => 0, 'island_turns' => 0, 'island_failures' => 0, 'tour_waiting' => false, 'hand' => []], 1 => ['laps' => 0, 'island_turns' => 0, 'island_failures' => 0, 'tour_waiting' => false, 'hand' => []]]],
        ]);
        $owner = Player::query()->create(['room_id' => $room->id, 'seat' => 0, 'turn_order' => 0, 'name' => 'Lan', 'player_token' => 'owner', 'cash' => $cash, 'position' => $position]);
        $guest = Player::query()->create(['room_id' => $room->id, 'seat' => 1, 'turn_order' => 1, 'name' => 'Minh', 'player_token' => 'guest', 'cash' => 2000000, 'position' => 0]);

        return [$room, $owner, $guest];
    }

    private function gamePost(string $path, array $data = [], string $token = 'owner')
    {
        return $this->postJson('/api/games/cr/rooms/SPEC01/'.$path, $data, ['X-Player-Token' => $token]);
    }

    public function test_board_and_economy_match_the_spec(): void
    {
        $settings = BoardDefinition::settings();
        $this->assertSame(3, $settings['rules_version']);
        $this->assertSame(2000000, $settings['starting_cash']);
        $this->assertSame(300000, $settings['start_reward']);
        $this->assertSame(32, count($settings['tiles']));
        $this->assertSame(['start', 'island', 'championship', 'world_tour'], array_map(fn ($i) => $settings['tiles'][$i]['special'], [0, 8, 16, 24]));
        $this->assertSame('tax', $settings['tiles'][28]['type']);
        $this->assertSame(4, collect($settings['tiles'])->where('type', 'service')->count());
        $this->assertSame(3, collect($settings['tiles'])->where('type', 'chance')->count());
    }

    public function test_double_grants_another_roll_and_third_double_goes_to_island_without_moving(): void
    {
        [$room, $player] = $this->game();
        DiceService::$generator = static fn (): int => 1;
        $this->gamePost('roll')->assertOk()->assertJsonPath('data.state.pending.action', 'buy');
        $this->gamePost('skip')->assertOk()->assertJsonPath('data.you.can_roll', true);
        $this->gamePost('roll')->assertOk()->assertJsonPath('data.state.pending.action', 'card');
        // Resolve the chance tile quickly, then the third consecutive double sends the player to tile 8.
        ChanceDeck::$nextId = 'business_bonus';
        $this->gamePost('card')->assertOk();
        $before = $player->fresh()->cash;
        $this->gamePost('roll')->assertOk();
        $this->assertSame(8, $player->fresh()->position);
        $this->assertSame($before, $player->fresh()->cash);
        $this->assertSame(1, GameRules::stats($room->fresh(), 0)['island_turns']);
    }

    public function test_passing_start_pays_once_and_unlocks_level_three(): void
    {
        [$room, $player] = $this->game(31);
        DiceService::$generator = static fn (): int => 1;
        $result = $this->gamePost('roll')->assertOk();
        $this->assertSame(2300000, $player->fresh()->cash);
        $this->assertSame(1, GameRules::stats($room->fresh(), 0)['laps']);
        $this->assertSame([0, 1, 2, 3], array_column($result->json('data.you.purchase_options'), 'level'));
    }

    public function test_initial_purchase_uses_land_and_house_prices_and_blocks_level_three_before_a_lap(): void
    {
        [$room, $player] = $this->game(1);
        $state = $room->state;
        $state['pending'] = ['action' => 'buy', 'tile' => 1];
        $room->state = $state;
        $room->phase = 'BUY';
        $room->save();
        $this->gamePost('buy', ['level' => 3])->assertStatus(422);
        $this->gamePost('buy', ['level' => 2])->assertOk();
        $this->assertSame(1800000, $player->fresh()->cash);
        $this->assertSame(2, BoardProperty::query()->where('room_id', $room->id)->value('level'));
    }

    public function test_tax_is_ten_percent_of_listed_assets_and_not_cash(): void
    {
        [$room, $player] = $this->game(26, 10000);
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 1, 'owner_seat' => 0, 'level' => 2]);
        DiceService::$generator = static fn (): int => 1;
        $this->gamePost('roll')->assertOk()->assertJsonPath('data.state.pending.action', 'debt')->assertJsonPath('data.state.pending.amount', 20000);
        $this->assertSame(10000, $player->fresh()->cash);
    }

    public function test_world_tour_waits_until_next_turn_and_only_exposes_eligible_properties(): void
    {
        [$room, $player] = $this->game(22);
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 1, 'owner_seat' => 1, 'level' => 0]);
        DiceService::$generator = static fn (): int => 1;
        $this->gamePost('roll')->assertOk();
        $this->assertSame(1, $room->fresh()->current_seat);
        $this->gamePost('end-turn', token: 'guest')->assertOk()->assertJsonPath('data.state.pending.action', 'tour');
        $targets = $room->fresh()->state['pending']['targets'];
        $this->assertNotContains(1, $targets);
        $this->assertContains(2, $targets);
    }

    public function test_resort_monopoly_wins_immediately(): void
    {
        [$room] = $this->game(27);
        foreach ([3, 11, 19] as $index) {
            BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => $index, 'owner_seat' => 0, 'level' => 0]);
        }
        $state = $room->state;
        $state['pending'] = ['action' => 'buy', 'tile' => 27];
        $room->state = $state;
        $room->phase = 'BUY';
        $room->save();
        $this->gamePost('buy', ['level' => 0])->assertOk()->assertJsonPath('data.room.status', MatchStatus::FINISHED)->assertJsonPath('data.room.win_reason', 'resort_monopoly');
    }

    public function test_weighted_chance_card_is_revealed_and_applied(): void
    {
        [$room, $player] = $this->game(4);
        $state = $room->state;
        $state['pending'] = ['action' => 'card', 'tile' => 4];
        $room->state = $state;
        $room->phase = 'CARD';
        $room->save();
        ChanceDeck::$nextId = 'business_bonus';
        $this->gamePost('card')->assertOk()->assertJsonPath('data.state.last_card.id', 'business_bonus');
        $this->assertSame(2100000, $player->fresh()->cash);
    }

    public function test_snapshot_exposes_the_current_landing_rent_instead_of_purchase_price(): void
    {
        [$room] = $this->game();
        BoardProperty::query()->create(['room_id' => $room->id, 'tile_index' => 1, 'owner_seat' => 1, 'level' => 2]);

        $response = $this->getJson('/api/games/cr/rooms/SPEC01', ['X-Player-Token' => 'owner'])->assertOk();

        $property = collect($response->json('data.properties'))->firstWhere('tile_index', 1);
        $this->assertSame(60000, $property['rent']);
        $this->assertNotSame($room->settings['tiles'][1]['price'], $property['rent']);
    }
}
