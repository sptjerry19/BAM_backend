<?php

namespace App\Services\CapitalRivals;

use App\Events\CapitalRivals\RoomUpdated;
use App\Exceptions\CapitalRivalsException;
use App\Models\CapitalRivals\LedgerEntry;
use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;
use App\Support\CapitalRivals\MatchStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TurnService
{
    public function __construct(private readonly RoomService $rooms)
    {
    }

    public function tick(string $code, ?string $token = null): array
    {
        return $this->mutate($code, function () use ($code, $token) {
            $room = $this->rooms->lockRoom($code);
            $viewer = $token ? $room->players->firstWhere('player_token', $token) : null;
            if ($this->expireIfDue($room)) {
                $room->version++;
                $room->save();
            }

            return $this->rooms->payload($room->fresh(['players', 'properties']), $viewer);
        });
    }

    public function endTurn(string $code, string $token): array
    {
        return $this->mutate($code, function () use ($code, $token) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($this->expireIfDue($room)) {
                $room->version++;
                $room->save();

                return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
            }
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? null;
            $canSkipBuy = ($pending['action'] ?? null) === 'buy';
            $canForfeit = $room->phase === MatchStatus::WAITING_FOR_ACTION && ! $pending;
            if (! $canSkipBuy && ! $canForfeit) {
                throw new CapitalRivalsException('invalid_state', 'Cannot end the turn now.');
            }
            $this->forfeitTurn($room, $canSkipBuy ? 'skip' : 'timeout');
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function bankrupt(string $code, string $token): array
    {
        return $this->mutate($code, function () use ($code, $token) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($expired = $this->payloadIfExpired($room, $player)) {
                return $expired;
            }
            $this->assertTurn($room, $player);
            $this->declareBankrupt($room, $player);
            $this->advanceTurn($room);
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function roll(string $code, string $token): array
    {
        return $this->mutate($code, function () use ($code, $token) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($expired = $this->payloadIfExpired($room, $player)) {
                return $expired;
            }
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? null;
            if ($room->phase !== MatchStatus::WAITING_FOR_ACTION || $pending) {
                throw new CapitalRivalsException('invalid_state', 'Cannot roll now.');
            }

            $count = (int) ($room->settings['dice_count'] ?? 2);
            $sides = (int) ($room->settings['dice_sides'] ?? 6);
            $dice = DiceService::roll($count, $sides);
            $moved = MovementService::advance((int) $player->position, array_sum($dice), 40);
            $player->position = $moved['position'];
            if ($moved['laps'] > 0) {
                $reward = (int) ($room->settings['start_reward'] ?? 200) * $moved['laps'];
                $this->credit($room, $player, $reward, 'start_bonus', null);
            }
            $player->save();

            $state = $room->state ?? [];
            $state['dice'] = $dice;
            $state['pending'] = null;
            $room->state = $state;
            $room->phase = MatchStatus::RESOLVING_TILE;
            $this->resolveLanding($room, $player);
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function buy(string $code, string $token): array
    {
        return $this->mutate($code, function () use ($code, $token) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($expired = $this->payloadIfExpired($room, $player)) {
                return $expired;
            }
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? null;
            if (($pending['action'] ?? null) !== 'buy') {
                throw new CapitalRivalsException('invalid_state', 'Nothing to buy.');
            }
            $price = (int) $pending['price'];
            if ((int) $player->cash < $price) {
                throw new CapitalRivalsException('insufficient_funds', 'Not enough cash to buy.');
            }
            $tileIndex = (int) $pending['tile'];
            if ($room->properties->firstWhere('tile_index', $tileIndex)) {
                throw new CapitalRivalsException('invalid_state', 'Tile already owned.');
            }

            $this->debit($room, $player, $price, 'buy', $tileIndex, null);
            $room->properties()->create([
                'tile_index' => $tileIndex,
                'owner_seat' => $player->seat,
                'level' => 0,
            ]);
            $this->advanceTurn($room);
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function skip(string $code, string $token): array
    {
        return $this->mutate($code, function () use ($code, $token) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($expired = $this->payloadIfExpired($room, $player)) {
                return $expired;
            }
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? null;
            if (($pending['action'] ?? null) !== 'buy') {
                throw new CapitalRivalsException('invalid_state', 'Nothing to skip.');
            }
            $this->advanceTurn($room);
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function pay(string $code, string $token, ?string $idempotencyKey = null): array
    {
        return $this->mutate($code, function () use ($code, $token, $idempotencyKey) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($expired = $this->payloadIfExpired($room, $player)) {
                return $expired;
            }
            $pending = $room->state['pending'] ?? null;
            $key = $idempotencyKey ?: (($pending['action'] ?? null) === 'pay' ? $this->payKey($room, $pending) : null);
            if ($key && LedgerEntry::query()->where('idempotency_key', $key)->exists()) {
                return $this->rooms->payload($room, $player);
            }
            $this->assertTurn($room, $player);
            if (($pending['action'] ?? null) !== 'pay') {
                throw new CapitalRivalsException('invalid_state', 'Nothing to pay.');
            }
            $this->settlePay($room, $player, $key ?: $this->payKey($room, $pending), $pending);
            if ($room->status !== MatchStatus::FINISHED) {
                $this->advanceTurn($room);
            }
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function card(string $code, string $token): array
    {
        return $this->mutate($code, function () use ($code, $token) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($expired = $this->payloadIfExpired($room, $player)) {
                return $expired;
            }
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? null;
            if (($pending['action'] ?? null) !== 'card') {
                throw new CapitalRivalsException('invalid_state', 'No dispatch to resolve.');
            }
            $this->resolveCard($room, $player);
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function upgrade(string $code, string $token, int $tileIndex): array
    {
        return $this->mutate($code, function () use ($code, $token, $tileIndex) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($expired = $this->payloadIfExpired($room, $player)) {
                return $expired;
            }
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? null;
            if ($room->phase !== MatchStatus::WAITING_FOR_ACTION || $pending) {
                throw new CapitalRivalsException('invalid_state', 'Cannot upgrade now.');
            }
            $tile = $this->tileAt($room, $tileIndex);
            if (($tile['type'] ?? '') !== 'property') {
                throw new CapitalRivalsException('invalid_state', 'Only properties can be upgraded.');
            }
            $owned = $room->properties->firstWhere('tile_index', $tileIndex);
            if (! $owned || (int) $owned->owner_seat !== (int) $player->seat) {
                throw new CapitalRivalsException('forbidden', 'You do not own this tile.', 403);
            }
            $max = (int) ($room->settings['max_upgrade_level'] ?? 4);
            if ((int) $owned->level >= $max) {
                throw new CapitalRivalsException('invalid_state', 'Already at max level.');
            }
            $group = (string) ($tile['group'] ?? '');
            if (! PropertyUpgrade::ownsGroup((int) $player->seat, $group, $room->properties, $room->settings['tiles'] ?? [])) {
                throw new CapitalRivalsException('invalid_state', 'Own the full group before upgrading.');
            }
            $cost = PropertyUpgrade::cost($tile, $room->settings);
            if ((int) $player->cash < $cost) {
                throw new CapitalRivalsException('insufficient_funds', 'Not enough cash to upgrade.');
            }
            $this->debit($room, $player, $cost, 'upgrade', $tileIndex, null);
            $owned->level = (int) $owned->level + 1;
            $owned->save();
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    private function assertTurn(Room $room, Player $player): void
    {
        if ($room->status !== MatchStatus::IN_PROGRESS) {
            throw new CapitalRivalsException('invalid_state', 'Match is not in progress.');
        }
        if ($player->bankrupt || (int) $player->seat !== (int) $room->current_seat) {
            throw new CapitalRivalsException('not_your_turn', 'It is not your turn.');
        }
    }

    private function resolveLanding(Room $room, Player $player): void
    {
        $tile = $this->tileAt($room, (int) $player->position);
        $type = $tile['type'] ?? 'rest';

        if (in_array($type, ['property', 'service'], true)) {
            $owned = $room->properties->firstWhere('tile_index', (int) $tile['index']);
            if (! $owned) {
                $this->setPending($room, [
                    'action' => 'buy',
                    'tile' => (int) $tile['index'],
                    'price' => (int) ($tile['price'] ?? 0),
                    'name' => $tile['name'] ?? '',
                ]);
                $room->phase = MatchStatus::RESOLVING_TILE;

                return;
            }
            if ((int) $owned->owner_seat === (int) $player->seat) {
                $this->advanceTurn($room);

                return;
            }
            $rent = RentCalculator::amount($tile, (int) $owned->owner_seat, $room->properties, $room->settings['tiles'] ?? []);
            $this->setPending($room, [
                'action' => 'pay',
                'kind' => 'rent',
                'tile' => (int) $tile['index'],
                'amount' => $rent,
                'to_seat' => (int) $owned->owner_seat,
                'name' => $tile['name'] ?? '',
            ]);
            $room->phase = MatchStatus::WAITING_FOR_PAYMENT;

            return;
        }

        if ($type === 'tax') {
            $this->setPending($room, [
                'action' => 'pay',
                'kind' => 'tax',
                'tile' => (int) $tile['index'],
                'amount' => (int) ($tile['amount'] ?? 0),
                'to_seat' => null,
                'name' => $tile['name'] ?? '',
            ]);
            $room->phase = MatchStatus::WAITING_FOR_PAYMENT;

            return;
        }

        if ($type === 'chance') {
            $this->setPending($room, [
                'action' => 'card',
                'tile' => (int) $tile['index'],
                'name' => $tile['name'] ?? 'Dispatch',
            ]);
            $room->phase = MatchStatus::RESOLVING_TILE;

            return;
        }

        if ($type === 'go_to_detention') {
            $player->position = 10;
            $player->save();
            $this->advanceTurn($room);

            return;
        }

        $this->advanceTurn($room);
    }

    private function payloadIfExpired(Room $room, ?Player $player): ?array
    {
        if (! $this->expireIfDue($room)) {
            return null;
        }
        $room->version++;
        $room->save();

        return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
    }

    private function expireIfDue(Room $room): bool
    {
        if ($room->status !== MatchStatus::IN_PROGRESS || ! $room->turn_deadline) {
            return false;
        }
        if ($room->turn_deadline->getTimestamp() > now()->getTimestamp()) {
            return false;
        }
        $current = $room->players->firstWhere('seat', $room->current_seat);
        $pending = $room->state['pending'] ?? null;
        $action = $pending['action'] ?? null;
        if ($current && $action === 'pay') {
            $this->settlePay($room, $current, $this->payKey($room, $pending, 'timeout'), $pending);
            if ($room->status !== MatchStatus::FINISHED) {
                $this->advanceTurn($room);
            }

            return true;
        }
        if ($current && $action === 'card') {
            $this->resolveCard($room, $current);

            return true;
        }
        $this->forfeitTurn($room, 'timeout');

        return true;
    }

    private function forfeitTurn(Room $room, string $reason): void
    {
        $current = $room->players->firstWhere('seat', $room->current_seat);
        if ($current) {
            $this->ledger($room, $reason, $current->id, null, 0, null);
        }
        $this->advanceTurn($room);
    }

    private function declareBankrupt(Room $room, Player $player): void
    {
        $player->bankrupt = true;
        $player->save();
        $room->properties()->where('owner_seat', $player->seat)->delete();
        $room->unsetRelation('properties');
        $room->load('properties');
        $this->ledger($room, 'bankrupt', $player->id, null, (int) $player->cash, null);
    }

    private function advanceTurn(Room $room): void
    {
        $room->load('players');
        $alive = $room->players->where('bankrupt', false)->sortBy('turn_order')->values();
        $state = $room->state ?? [];
        $state['pending'] = null;
        $state['turns_played'] = (int) ($state['turns_played'] ?? 0) + 1;

        if ($alive->count() <= 1) {
            $this->finishMatch($room, $alive->first()?->seat, 'last_standing', $state);

            return;
        }

        $maxTurns = (int) ($room->settings['max_turns'] ?? 0);
        if ($maxTurns > 0 && $state['turns_played'] >= $maxTurns) {
            $this->finishMatch($room, $this->richestSeat($room), 'max_turns', $state);

            return;
        }

        $current = $room->players->firstWhere('seat', $room->current_seat);
        $order = (int) ($current?->turn_order ?? 0);
        $next = $alive->first(fn (Player $p) => (int) $p->turn_order > $order) ?? $alive->first();
        $room->current_seat = $next->seat;
        $room->status = MatchStatus::IN_PROGRESS;
        $room->phase = MatchStatus::WAITING_FOR_ACTION;
        $room->turn_deadline = now()->addSeconds((int) ($room->settings['turn_timeout'] ?? 30));
        $room->state = $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function finishMatch(Room $room, ?int $winnerSeat, string $reason, array $state): void
    {
        $room->status = MatchStatus::FINISHED;
        $room->phase = MatchStatus::FINISHED;
        $room->finished_at = now();
        $state['winner_seat'] = $winnerSeat;
        $state['win_reason'] = $reason;
        $state['pending'] = null;
        $room->state = $state;
    }

    private function richestSeat(Room $room): ?int
    {
        $tiles = collect($room->settings['tiles'] ?? [])->keyBy('index');
        $worth = [];
        foreach ($room->players as $player) {
            $sum = (int) $player->cash;
            foreach ($room->properties->where('owner_seat', $player->seat) as $owned) {
                $sum += (int) ($tiles[$owned->tile_index]['price'] ?? 0);
            }
            $worth[(int) $player->seat] = $sum;
        }
        if ($worth === []) {
            return null;
        }
        arsort($worth);

        return (int) array_key_first($worth);
    }

    /**
     * @return array<string, mixed>
     */
    private function tileAt(Room $room, int $index): array
    {
        foreach ($room->settings['tiles'] ?? [] as $tile) {
            if ((int) ($tile['index'] ?? -1) === $index) {
                return $tile;
            }
        }

        throw new CapitalRivalsException('invalid_state', 'Unknown tile.');
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function setPending(Room $room, array $pending): void
    {
        $state = $room->state ?? [];
        $state['pending'] = $pending;
        $room->state = $state;
    }

    private function credit(Room $room, Player $player, int $amount, string $type, ?int $tile): void
    {
        if ($amount <= 0) {
            return;
        }
        $player->cash = (int) $player->cash + $amount;
        $player->save();
        $this->ledger($room, $type, null, $player->id, $amount, $tile);
    }

    private function debit(Room $room, Player $player, int $amount, string $type, ?int $tile, ?Player $to, ?string $key = null): void
    {
        if ($amount <= 0) {
            return;
        }
        $paid = min((int) $player->cash, $amount);
        $player->cash = (int) $player->cash - $paid;
        $player->bankrupt = $paid < $amount;
        $player->save();
        if ($to) {
            $to->cash = (int) $to->cash + $paid;
            $to->save();
        }
        $this->ledger($room, $type, $player->id, $to?->id, $paid, $tile, $key);
        if ($player->bankrupt) {
            $this->declareBankrupt($room, $player);
        }
    }

    private function transfer(Room $room, Player $from, ?Player $to, int $amount, string $type, ?int $tile): void
    {
        $this->debit($room, $from, $amount, $type, $tile, $to);
    }

    private function ledger(Room $room, string $type, ?int $from, ?int $to, int $amount, ?int $tile, ?string $key = null): void
    {
        LedgerEntry::query()->create([
            'room_id' => $room->id,
            'type' => $type,
            'from_player_id' => $from,
            'to_player_id' => $to,
            'amount' => $amount,
            'tile_index' => $tile,
            'idempotency_key' => $key ?: $room->id.'-'.$room->version.'-'.$type.'-'.($from ?? 0).'-'.($tile ?? 'x').'-'.uniqid(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function payKey(Room $room, array $pending, string $suffix = 'pay'): string
    {
        return implode(':', [
            'cr',
            $room->id,
            $room->version,
            $pending['kind'] ?? 'pay',
            $pending['tile'] ?? 'x',
            $suffix,
        ]);
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function settlePay(Room $room, Player $player, string $key, array $pending): void
    {
        if (LedgerEntry::query()->where('idempotency_key', $key)->exists()) {
            return;
        }
        $amount = (int) ($pending['amount'] ?? 0);
        $kind = (string) ($pending['kind'] ?? 'tax');
        $tile = isset($pending['tile']) ? (int) $pending['tile'] : null;
        $to = isset($pending['to_seat']) ? $room->players->firstWhere('seat', (int) $pending['to_seat']) : null;
        $this->debit($room, $player, $amount, $kind, $tile, $to, $key);
        $player->refresh();
    }

    private function resolveCard(Room $room, Player $player): void
    {
        $state = $room->state ?? [];
        $card = ChanceDeck::draw($state);
        $state['last_card'] = $card;
        $state['pending'] = null;
        $room->state = $state;

        $kind = $card['kind'] ?? 'credit';
        if ($kind === 'credit') {
            $this->credit($room, $player, (int) ($card['amount'] ?? 0), 'chance', (int) $player->position);
        } elseif ($kind === 'debit') {
            $this->debit($room, $player, (int) ($card['amount'] ?? 0), 'chance', (int) $player->position, null);
        } elseif ($kind === 'move') {
            $player->position = (int) ($card['position'] ?? 10);
            $player->save();
        } elseif ($kind === 'go_start') {
            $player->position = 0;
            $player->save();
            $this->credit($room, $player, (int) ($room->settings['start_reward'] ?? 200), 'start_bonus', 0);
        }

        if ($room->status !== MatchStatus::FINISHED) {
            $this->advanceTurn($room);
        }
    }

    /**
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    private function mutate(string $code, callable $fn): mixed
    {
        $lock = Cache::lock('cr:room:'.strtoupper($code), 12);
        if (! $lock->get()) {
            throw new CapitalRivalsException('busy', 'Room is busy.', 409);
        }
        try {
            $payload = DB::transaction($fn);
            if (is_array($payload) && isset($payload['room']['code'], $payload['room']['version'])) {
                event(new RoomUpdated((string) $payload['room']['code'], (int) $payload['room']['version']));
            }

            return $payload;
        } finally {
            $lock->release();
        }
    }
}
