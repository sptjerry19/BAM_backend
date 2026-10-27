<?php

namespace Tests\Feature\CapitalRivals;

use App\Models\CapitalRivals\Room;
use App\Services\CapitalRivals\DiceService;
use App\Support\CapitalRivals\MatchStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesCapitalRivalsMatch;
use Tests\TestCase;

class TimeoutVictoryTest extends TestCase
{
    use CreatesCapitalRivalsMatch;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        DiceService::$generator = null;
        parent::tearDown();
    }

    public function test_expired_turn_advances_on_snapshot(): void
    {
        [$code, $first] = $this->startedPair();
        $before = $this->getJson("/api/games/cr/rooms/{$code}", ['X-Player-Token' => $first]);
        $seat = $before->json('data.room.current_seat');

        Room::query()->where('code', $code)->update(['turn_deadline' => now()->subSeconds(5)]);

        $after = $this->getJson("/api/games/cr/rooms/{$code}", ['X-Player-Token' => $first]);
        $after->assertOk();
        $this->assertNotSame($seat, $after->json('data.room.current_seat'));
        $this->assertSame(MatchStatus::WAITING_FOR_ACTION, $after->json('data.room.phase'));
        $this->assertTrue(collect($after->json('data.ledger'))->contains(fn ($row) => $row['type'] === 'timeout'));
    }

    public function test_bankrupt_finishes_two_player_match(): void
    {
        [$code, $first] = $this->startedPair();
        $gone = $this->postJson("/api/games/cr/rooms/{$code}/bankrupt", [], ['X-Player-Token' => $first]);
        $gone->assertOk();
        $gone->assertJsonPath('data.room.status', MatchStatus::FINISHED);
        $gone->assertJsonPath('data.room.win_reason', 'last_standing');
        $this->assertNotNull($gone->json('data.room.winner_seat'));
        $this->assertTrue(collect($gone->json('data.players'))->firstWhere('seat', $gone->json('data.you.seat'))['bankrupt']);
    }

    public function test_max_turns_awards_richest(): void
    {
        DiceService::$generator = static fn () => 2;
        [$code, $first] = $this->startedPair();
        $room = Room::query()->where('code', $code)->first();
        $settings = $room->settings;
        $settings['max_turns'] = 1;
        $room->settings = $settings;
        $room->save();

        $roll = $this->postJson("/api/games/cr/rooms/{$code}/roll", [], ['X-Player-Token' => $first]);
        $roll->assertOk();
        $pay = $this->postJson("/api/games/cr/rooms/{$code}/pay", [], ['X-Player-Token' => $first]);
        $pay->assertOk();
        $pay->assertJsonPath('data.room.status', MatchStatus::FINISHED);
        $pay->assertJsonPath('data.room.win_reason', 'max_turns');
        $this->assertNotNull($pay->json('data.room.winner_seat'));
    }
}
