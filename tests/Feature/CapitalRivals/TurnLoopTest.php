<?php

namespace Tests\Feature\CapitalRivals;

use App\Models\CapitalRivals\Player;
use App\Services\CapitalRivals\DiceService;
use App\Support\CapitalRivals\MatchStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesCapitalRivalsMatch;
use Tests\TestCase;

class TurnLoopTest extends TestCase
{
    use CreatesCapitalRivalsMatch;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        DiceService::$generator = null;
        parent::tearDown();
    }

    public function test_roll_buy_then_rent(): void
    {
        $this->scriptDice([1, 2, 1, 2]);
        [$code, $first, $second] = $this->startedPair();

        $roll = $this->postJson("/api/games/cr/rooms/{$code}/roll", [], ['X-Player-Token' => $first]);
        $roll->assertOk();
        $roller = collect($roll->json('data.players'))->firstWhere('seat', $roll->json('data.you.seat'));
        $this->assertSame(3, $roller['position']);
        $this->assertSame('buy', $roll->json('data.state.pending.action'));
        $this->assertSame(60, $roll->json('data.state.pending.price'));

        $this->postJson("/api/games/cr/rooms/{$code}/roll", [], ['X-Player-Token' => $first])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'invalid_state');

        $buy = $this->postJson("/api/games/cr/rooms/{$code}/buy", [], ['X-Player-Token' => $first]);
        $buy->assertOk();
        $buyerSeat = $buy->json('data.you.seat');
        $this->assertSame(1440, collect($buy->json('data.players'))->firstWhere('seat', $buyerSeat)['cash']);
        $this->assertSame(3, $buy->json('data.properties.0.tile_index'));
        $this->assertSame(MatchStatus::WAITING_FOR_ACTION, $buy->json('data.room.phase'));

        $rentRoll = $this->postJson("/api/games/cr/rooms/{$code}/roll", [], ['X-Player-Token' => $second]);
        $rentRoll->assertOk();
        $this->assertSame('pay', $rentRoll->json('data.state.pending.action'));
        $this->assertSame(4, $rentRoll->json('data.state.pending.amount'));

        $pay = $this->postJson("/api/games/cr/rooms/{$code}/pay", [], ['X-Player-Token' => $second]);
        $pay->assertOk();
        $payer = collect($pay->json('data.players'))->firstWhere('seat', $pay->json('data.you.seat'));
        $owner = collect($pay->json('data.players'))->firstWhere('seat', $buyerSeat);
        $this->assertSame(1496, $payer['cash']);
        $this->assertSame(1444, $owner['cash']);
        $this->assertTrue(collect($pay->json('data.ledger'))->contains(fn ($row) => $row['type'] === 'rent' && $row['amount'] === 4));
    }

    public function test_tax_is_logged_and_deducted(): void
    {
        $this->scriptDice([2, 2]);
        [$code, $first] = $this->startedPair();

        $roll = $this->postJson("/api/games/cr/rooms/{$code}/roll", [], ['X-Player-Token' => $first]);
        $roll->assertOk();
        $this->assertSame('pay', $roll->json('data.state.pending.action'));
        $this->assertSame(200, $roll->json('data.state.pending.amount'));

        $pay = $this->postJson("/api/games/cr/rooms/{$code}/pay", [], ['X-Player-Token' => $first]);
        $pay->assertOk();
        $player = collect($pay->json('data.players'))->firstWhere('seat', $pay->json('data.you.seat'));
        $this->assertSame(4, $player['position']);
        $this->assertSame(1300, $player['cash']);
        $this->assertTrue(collect($pay->json('data.ledger'))->contains(fn ($row) => $row['type'] === 'tax' && $row['amount'] === 200));
        $this->assertNull($pay->json('data.state.pending'));
    }

    public function test_passing_start_adds_reward(): void
    {
        $this->scriptDice([1, 1]);
        [$code, $first] = $this->startedPair();
        $snap = $this->getJson("/api/games/cr/rooms/{$code}", ['X-Player-Token' => $first]);
        $seat = $snap->json('data.you.seat');
        Player::query()->where('player_token', $first)->update(['position' => 38]);

        $roll = $this->postJson("/api/games/cr/rooms/{$code}/roll", [], ['X-Player-Token' => $first]);
        $roll->assertOk();
        $player = collect($roll->json('data.players'))->firstWhere('seat', $seat);
        $this->assertSame(0, $player['position']);
        $this->assertSame(1700, $player['cash']);
        $this->assertTrue(collect($roll->json('data.ledger'))->contains(fn ($row) => $row['type'] === 'start_bonus' && $row['amount'] === 200));
    }

    public function test_wrong_player_cannot_roll(): void
    {
        $this->scriptDice([1, 1]);
        [$code, $first, $second] = $this->startedPair();
        $this->postJson("/api/games/cr/rooms/{$code}/roll", [], ['X-Player-Token' => $second])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'not_your_turn');
    }

    public function test_buy_rejected_when_broke(): void
    {
        $this->scriptDice([1, 2]);
        [$code, $first] = $this->startedPair();
        Player::query()->where('player_token', $first)->update(['cash' => 10]);
        $this->postJson("/api/games/cr/rooms/{$code}/roll", [], ['X-Player-Token' => $first])->assertOk();
        $this->postJson("/api/games/cr/rooms/{$code}/buy", [], ['X-Player-Token' => $first])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'insufficient_funds');
    }

    public function test_duplicate_pay_is_idempotent(): void
    {
        $this->scriptDice([2, 2]);
        [$code, $first] = $this->startedPair();
        $this->postJson("/api/games/cr/rooms/{$code}/roll", [], ['X-Player-Token' => $first])->assertOk();
        $headers = ['X-Player-Token' => $first, 'Idempotency-Key' => 'tax-once'];
        $firstPay = $this->postJson("/api/games/cr/rooms/{$code}/pay", [], $headers);
        $firstPay->assertOk();
        $cash = collect($firstPay->json('data.players'))->firstWhere('seat', $firstPay->json('data.you.seat'))['cash'];
        $again = $this->postJson("/api/games/cr/rooms/{$code}/pay", [], $headers);
        $again->assertOk();
        $this->assertSame(
            $cash,
            collect($again->json('data.players'))->firstWhere('seat', $firstPay->json('data.you.seat'))['cash'],
        );
        $this->assertSame(1, collect($again->json('data.ledger'))->where('type', 'tax')->count());
    }
}
