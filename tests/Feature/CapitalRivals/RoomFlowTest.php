<?php

namespace Tests\Feature\CapitalRivals;

use App\Support\CapitalRivals\MatchStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RoomFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_host_cannot_start_alone(): void
    {
        $create = $this->postJson('/api/games/cr/rooms', ['name' => 'Host']);
        $token = $create->json('data.you.player_token');
        $code = $create->json('data.room.code');

        $this->postJson("/api/games/cr/rooms/{$code}/start", [], ['X-Player-Token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'invalid_state');
    }

    public function test_create_join_ready_start(): void
    {
        $create = $this->postJson('/api/games/cr/rooms', ['name' => 'Host']);
        $create->assertCreated();
        $create->assertJsonPath('success', true);
        $create->assertJsonPath('data.room.status', MatchStatus::WAITING_FOR_PLAYERS);
        $create->assertJsonPath('data.room.tile_count', 40);
        $code = $create->json('data.room.code');
        $hostToken = $create->json('data.you.player_token');
        $this->assertNotEmpty($hostToken);

        $join = $this->postJson("/api/games/cr/rooms/{$code}/join", ['name' => 'Guest']);
        $join->assertOk();
        $guestToken = $join->json('data.you.player_token');
        $this->assertNotSame($hostToken, $guestToken);
        $join->assertJsonPath('data.room.status', MatchStatus::WAITING_FOR_PLAYERS);

        $this->postJson("/api/games/cr/rooms/{$code}/start", [], ['X-Player-Token' => $hostToken])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'invalid_state');

        $this->postJson("/api/games/cr/rooms/{$code}/ready", ['ready' => true], ['X-Player-Token' => $guestToken])
            ->assertOk()
            ->assertJsonPath('data.room.status', MatchStatus::READY);

        $start = $this->postJson("/api/games/cr/rooms/{$code}/start", [], ['X-Player-Token' => $hostToken]);
        $start->assertOk();
        $start->assertJsonPath('data.room.status', MatchStatus::IN_PROGRESS);
        $start->assertJsonPath('data.room.phase', MatchStatus::WAITING_FOR_ACTION);
        $this->assertContains($start->json('data.room.current_seat'), [0, 1]);
        $this->assertCount(2, $start->json('data.players'));
        foreach ($start->json('data.players') as $player) {
            $this->assertSame(1500, $player['cash']);
            $this->assertSame(0, $player['position']);
            $this->assertArrayNotHasKey('player_token', $player);
        }

        $snap = $this->getJson("/api/games/cr/rooms/{$code}", ['X-Player-Token' => $guestToken]);
        $snap->assertOk();
        $snap->assertJsonPath('data.you.seat', 1);
        $this->assertNull($snap->json('data.you.player_token'));
    }

    public function test_fifth_player_cannot_join(): void
    {
        $create = $this->postJson('/api/games/cr/rooms', ['name' => 'A']);
        $code = $create->json('data.room.code');
        foreach (['B', 'C', 'D'] as $name) {
            $this->postJson("/api/games/cr/rooms/{$code}/join", ['name' => $name])->assertOk();
        }
        $this->postJson("/api/games/cr/rooms/{$code}/join", ['name' => 'E'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'room_full');
    }

    public function test_rejoin_with_same_token_is_idempotent(): void
    {
        $create = $this->postJson('/api/games/cr/rooms', ['name' => 'Host']);
        $code = $create->json('data.room.code');
        $join = $this->postJson("/api/games/cr/rooms/{$code}/join", ['name' => 'Guest']);
        $token = $join->json('data.you.player_token');

        $again = $this->postJson("/api/games/cr/rooms/{$code}/join", [
            'name' => 'Guest',
            'player_token' => $token,
        ]);
        $again->assertOk();
        $this->assertSame($token, $again->json('data.you.player_token'));
        $this->assertCount(2, $again->json('data.players'));
    }
}
