<?php

namespace App\Services\CapitalRivals;

use App\Exceptions\CapitalRivalsException;
use App\Models\CapitalRivals\BoardProperty;
use App\Models\CapitalRivals\LedgerEntry;
use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;
use App\Support\CapitalRivals\MatchStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Server-authoritative engine for business-tour-game-design-v1.md (rules v3). */
final class SpecTurnService
{
    public function __construct(private readonly RoomService $rooms) {}

    public function tick(string $code, ?string $token = null): array
    {
        return $this->mutate($code, function (Room $room) use ($token): array {
            $viewer = $token ? $room->players->firstWhere('player_token', $token) : null;
            if ($room->status === MatchStatus::IN_PROGRESS && $room->turn_deadline?->isPast()) {
                $this->timeout($room);
                $room->version++;
                $room->save();
            }

            return $this->rooms->payload($room->fresh(['players', 'properties']), $viewer);
        });
    }

    public function roll(string $code, string $token): array
    {
        return $this->withPlayer($code, $token, function (Room $room, Player $player): void {
            $this->assertTurn($room, $player);
            if (($room->state['pending'] ?? null) || ! in_array($room->phase, ['PRE_ROLL', MatchStatus::WAITING_FOR_ACTION], true)) {
                $this->invalid('Không thể gieo xúc xắc lúc này.');
            }
            if (GameRules::stats($room, $player->seat)['island_turns'] > 0 || GameRules::stats($room, $player->seat)['tour_waiting']) {
                $this->invalid('Bạn phải xử lý hành động đầu lượt trước.');
            }
            $this->performRoll($room, $player, false);
        });
    }

    public function buy(string $code, string $token, int $level = 0): array
    {
        return $this->withPlayer($code, $token, function (Room $room, Player $player) use ($level): void {
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? [];
            if (($pending['action'] ?? '') !== 'buy') {
                $this->invalid('Không có bất động sản nào đang chờ mua.');
            }
            $tile = $this->tile($room, (int) $pending['tile']);
            $level = ($tile['type'] ?? '') === 'property' ? $level : 0;
            $max = GameRules::stats($room, $player->seat)['laps'] > 0 ? 3 : 2;
            if ($level < 0 || $level > $max) {
                $this->invalid('Cấp xây dựng ban đầu không hợp lệ.');
            }
            $price = $this->value($tile, $level, $room);
            if ($player->cash < $price) {
                throw new CapitalRivalsException('insufficient_funds', 'Không đủ tiền để mua.', 422);
            }
            if ($room->properties->firstWhere('tile_index', (int) $tile['index'])) {
                $this->invalid('Ô này đã có chủ.');
            }
            $this->transfer($room, $player, null, $price, 'buy', (int) $tile['index']);
            $room->properties()->create(['tile_index' => $tile['index'], 'owner_seat' => $player->seat, 'level' => $level]);
            $room->load('properties');
            if (! $this->checkVictory($room, $player)) {
                $this->completeLanding($room);
            }
        });
    }

    public function upgrade(string $code, string $token, int $tileIndex): array
    {
        return $this->withPlayer($code, $token, function (Room $room, Player $player) use ($tileIndex): void {
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? [];
            if (($pending['action'] ?? '') !== 'build' || (int) ($pending['tile'] ?? -1) !== $tileIndex) {
                $this->invalid('Không thể xây dựng lúc này.');
            }
            $property = $room->properties->firstWhere('tile_index', $tileIndex);
            $tile = $this->tile($room, $tileIndex);
            if (! $property || $property->owner_seat !== $player->seat || $property->level >= 4) {
                $this->invalid('Công trình không hợp lệ.');
            }
            $next = $property->level + 1;
            if ($next >= 3 && GameRules::stats($room, $player->seat)['laps'] < 1) {
                $this->invalid('Phải hoàn thành một vòng trước khi xây cấp 3.');
            }
            $cost = $next === 4 ? (int) $tile['hotel_price'] : (int) $tile['house_price'];
            if ($player->cash < $cost) {
                throw new CapitalRivalsException('insufficient_funds', 'Không đủ tiền để xây dựng.', 422);
            }
            $this->transfer($room, $player, null, $cost, $next === 4 ? 'build_hotel' : 'build_house', $tileIndex);
            $property->level = $next;
            $property->save();
            $this->completeLanding($room);
        });
    }

    public function skip(string $code, string $token): array
    {
        return $this->withPlayer($code, $token, function (Room $room, Player $player): void {
            $this->assertTurn($room, $player);
            $action = $room->state['pending']['action'] ?? '';
            if (in_array($action, ['buy', 'build', 'buyout', 'championship'], true)) {
                $this->completeLanding($room);

                return;
            }
            if ($action === 'tour') {
                GameRules::update($room, $player->seat, ['tour_waiting' => false]);
                $this->clearPending($room);
                $room->phase = 'PRE_ROLL';
                $room->turn_deadline = now()->addSeconds((int) $room->settings['roll_timeout']);

                return;
            }
            if ($action === 'discard_card') {
                $this->clearPending($room);
                $this->completeLanding($room);

                return;
            }
            $this->invalid('Không có hành động nào để bỏ qua.');
        });
    }

    public function card(string $code, string $token): array
    {
        return $this->withPlayer($code, $token, function (Room $room, Player $player): void {
            $this->assertTurn($room, $player);
            if (($room->state['pending']['action'] ?? '') !== 'card') {
                $this->invalid('Không có thẻ Cơ hội đang chờ.');
            }
            $card = ChanceDeck::drawSpec();
            $state = $room->state;
            $state['last_card'] = [...$card, 'draw_id' => bin2hex(random_bytes(8)), 'player_name' => $player->name];
            $room->state = $state;
            $this->resolveCard($room, $player, $state['last_card']);
        });
    }

    public function action(string $code, string $token, string $action, ?int $target): array
    {
        return $this->withPlayer($code, $token, function (Room $room, Player $player) use ($action, $target): void {
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? [];
            if ($action === 'island_pay') {
                $this->islandPay($room, $player);
            } elseif ($action === 'island_roll') {
                $this->islandRoll($room, $player);
            } elseif ($action === 'use_escape_card') {
                $this->escapeCard($room, $player);
            } elseif ($action === 'travel') {
                $this->travel($room, $player, $target);
            } elseif ($action === 'championship') {
                $this->championship($room, $player, $target, false);
            } elseif ($action === 'takeover') {
                $this->buyout($room, $player);
            } elseif ($action === 'decline_takeover') {
                if (($pending['action'] ?? '') !== 'buyout') {
                    $this->invalid('Không có quyền thâu tóm đang chờ.');
                }
                $this->completeLanding($room);
            } elseif ($action === 'chance_target') {
                $this->chanceTarget($room, $player, $target);
            } elseif ($action === 'use_shield') {
                $this->useShield($room, $player, $target);
            } elseif ($action === 'liquidate') {
                $this->liquidate($room, $player, $target);
            } elseif ($action === 'discard_card') {
                $this->discardCard($room, $player, $target);
            } elseif ($action === 'surrender') {
                $this->eliminate($room, $player, false, null);
            } else {
                $this->invalid('Hành động không được hỗ trợ trong luật hiện tại.');
            }
        });
    }

    public function endTurn(string $code, string $token): array
    {
        return $this->withPlayer($code, $token, function (Room $room, Player $player): void {
            $this->assertTurn($room, $player);
            if (($room->state['pending'] ?? null) !== null) {
                $this->invalid('Hãy xử lý hành động đang chờ trước.');
            }
            $this->advanceTurn($room);
        });
    }

    public function bankrupt(string $code, string $token): array
    {
        return $this->withPlayer($code, $token, function (Room $room, Player $player): void {
            $this->assertTurn($room, $player);
            $this->eliminate($room, $player, true, $room->state['pending_debt']['creditor_seat'] ?? null);
        });
    }

    public function pay(string $code, string $token): array
    {
        return $this->withPlayer($code, $token, function (Room $room, Player $player): void {
            $this->assertTurn($room, $player);
            if (($room->state['pending']['action'] ?? '') !== 'debt') {
                $this->invalid('Không có khoản nợ đang chờ.');
            }
            $this->settleDebt($room, $player);
        });
    }

    private function performRoll(Room $room, Player $player, bool $islandEscape): void
    {
        $dice = DiceService::roll(2, 6);
        $double = $dice[0] === $dice[1];
        $state = $room->state;
        $state['dice'] = $dice;
        $streak = $islandEscape ? 0 : ($double ? (int) ($state['doubles_streak'] ?? 0) + 1 : 0);
        $state['doubles_streak'] = $streak;
        $state['extra_roll'] = ! $islandEscape && $double;
        $room->state = $state;
        if ($streak >= 3) {
            $player->position = 8;
            $player->save();
            GameRules::update($room, $player->seat, ['island_turns' => 1, 'island_failures' => 0]);
            $this->ledger($room, 'third_double_island', $player, null, 0, 8);
            $this->advanceTurn($room);

            return;
        }
        $from = $player->position;
        $steps = array_sum($dice);
        $player->position = ($from + $steps) % 32;
        if ($from + $steps >= 32) {
            $this->transfer($room, null, $player, (int) $room->settings['start_reward'], 'start_bonus', 0);
            $stats = GameRules::stats($room, $player->seat);
            GameRules::update($room, $player->seat, ['laps' => $stats['laps'] + 1]);
        }
        $player->save();
        $room->phase = 'RESOLVING_TILE';
        $this->resolveLanding($room, $player);
    }

    private function resolveLanding(Room $room, Player $player): void
    {
        $tile = $this->tile($room, $player->position);
        $type = $tile['type'];
        if (in_array($type, ['property', 'service'], true)) {
            $owned = $room->properties->firstWhere('tile_index', $player->position);
            if (! $owned) {
                $this->pending($room, ['action' => 'buy', 'tile' => $player->position]);
            } elseif ($owned->owner_seat === $player->seat) {
                if ($type === 'property' && $owned->level < 4) {
                    $this->pending($room, ['action' => 'build', 'tile' => $player->position]);
                } else {
                    $this->completeLanding($room);
                }
            } else {
                $rent = $this->rent($room, $tile, $owned);
                $continuation = ['kind' => 'after_rent', 'tile' => $player->position, 'owner_seat' => $owned->owner_seat];
                if ($this->chargeMandatory($room, $player, $rent, 'rent', $owned->owner_seat, $player->position, $continuation)) {
                    $this->afterRent($room, $player, $owned);
                }
            }
        } elseif ($type === 'chance') {
            $this->pending($room, ['action' => 'card', 'tile' => $player->position]);
        } elseif ($type === 'tax') {
            $amount = (int) floor($this->assetValue($room, $player) * (float) $room->settings['tax_rate']);
            if ($this->chargeMandatory($room, $player, $amount, 'tax', null, 28, ['kind' => 'complete'])) {
                $this->completeLanding($room);
            }
        } elseif ($type === 'detention') {
            GameRules::update($room, $player->seat, ['island_turns' => 1, 'island_failures' => 0]);
            $this->advanceTurn($room);
        } elseif ($type === 'world_tour') {
            GameRules::update($room, $player->seat, ['tour_waiting' => true]);
            $this->advanceTurn($room);
        } elseif ($type === 'championship') {
            $targets = $this->ownedCities($room, $player->seat);
            if ($targets === []) {
                $this->completeLanding($room);
            } else {
                $this->pending($room, ['action' => 'championship', 'targets' => $targets]);
            }
        } else {
            $this->completeLanding($room);
        }
    }

    private function completeLanding(Room $room): void
    {
        $state = $room->state;
        $extra = (bool) ($state['extra_roll'] ?? false);
        $state['pending'] = null;
        $state['extra_roll'] = false;
        $room->state = $state;
        if ($extra) {
            $room->phase = 'PRE_ROLL';
            $room->turn_deadline = now()->addSeconds((int) $room->settings['roll_timeout']);
        } else {
            $this->advanceTurn($room);
        }
    }

    private function advanceTurn(Room $room): void
    {
        if ($room->status !== MatchStatus::IN_PROGRESS) {
            return;
        }
        $deadline = $room->state['match_deadline'] ?? null;
        if ($deadline && now()->greaterThanOrEqualTo(Carbon::parse($deadline))) {
            $this->finishByTime($room);

            return;
        }
        $alive = $room->players->where('bankrupt', false)->sortBy('turn_order')->values();
        if ($alive->count() <= 1) {
            if ($alive->isNotEmpty()) {
                $this->finish($room, $alive->first(), 'last_survivor');
            }

            return;
        }
        $current = $room->players->firstWhere('seat', $room->current_seat);
        $index = $alive->search(fn (Player $p): bool => $p->id === $current?->id);
        $next = $alive[($index === false ? 0 : $index + 1) % $alive->count()];
        $state = $room->state;
        $state['pending'] = null;
        $state['pending_debt'] = null;
        $state['doubles_streak'] = 0;
        $state['extra_roll'] = false;
        $state['turn_id'] = (int) ($state['turn_id'] ?? 0) + 1;
        $state['turns_played'] = (int) ($state['turns_played'] ?? 0) + 1;
        foreach (($state['blackouts'] ?? []) as $tile => $expirySeat) {
            if ((int) $expirySeat === $next->seat) {
                unset($state['blackouts'][$tile]);
            }
        }
        $room->state = $state;
        GameRules::update($room, $next->seat, ['used_saved_card' => false]);
        $room->current_seat = $next->seat;
        $stats = GameRules::stats($room, $next->seat);
        if ($stats['tour_waiting']) {
            $targets = $this->tourTargets($room, $next);
            if ($next->cash >= (int) $room->settings['travel_fee'] && $targets !== []) {
                $this->pending($room, ['action' => 'tour', 'targets' => $targets]);

                return;
            }
            GameRules::update($room, $next->seat, ['tour_waiting' => false]);
        }
        if ($stats['island_turns'] > 0) {
            $this->pending($room, ['action' => 'island', 'failures' => $stats['island_failures']]);

            return;
        }
        $room->phase = 'PRE_ROLL';
        $room->turn_deadline = now()->addSeconds((int) $room->settings['roll_timeout']);
    }

    private function afterRent(Room $room, Player $payer, BoardProperty $property): void
    {
        $tile = $this->tile($room, $property->tile_index);
        if ($tile['type'] === 'property' && $property->level < 4 && $payer->cash >= 2 * $this->value($tile, $property->level, $room)) {
            $this->pending($room, ['action' => 'buyout', 'tile' => $property->tile_index, 'price' => 2 * $this->value($tile, $property->level, $room), 'owner_seat' => $property->owner_seat]);
        } else {
            $this->completeLanding($room);
        }
    }

    private function buyout(Room $room, Player $player): void
    {
        $pending = $room->state['pending'] ?? [];
        if (($pending['action'] ?? '') !== 'buyout') {
            $this->invalid('Không có quyền thâu tóm đang chờ.');
        }
        $property = $room->properties->firstWhere('tile_index', (int) $pending['tile']);
        if (! $property || $property->level >= 4 || $player->cash < (int) $pending['price']) {
            $this->invalid('Không thể thâu tóm bất động sản này.');
        }
        $owner = $room->players->firstWhere('seat', $property->owner_seat);
        $this->transfer($room, $player, $owner, (int) $pending['price'], 'buyout', $property->tile_index);
        $property->owner_seat = $player->seat;
        $property->save();
        $room->load('properties');
        if (! $this->checkVictory($room, $player)) {
            $this->completeLanding($room);
        }
    }

    private function islandPay(Room $room, Player $player): void
    {
        if (($room->state['pending']['action'] ?? '') !== 'island') {
            $this->invalid('Bạn không ở trạng thái Đảo hoang.');
        }
        $fee = (int) $room->settings['island_fee'];
        if ($player->cash < $fee) {
            throw new CapitalRivalsException('insufficient_funds', 'Không đủ tiền thoát đảo.', 422);
        }
        $this->transfer($room, $player, null, $fee, 'island_exit', 8);
        GameRules::update($room, $player->seat, ['island_turns' => 0, 'island_failures' => 0]);
        $this->clearPending($room);
        $room->phase = 'PRE_ROLL';
    }

    private function islandRoll(Room $room, Player $player): void
    {
        if (($room->state['pending']['action'] ?? '') !== 'island') {
            $this->invalid('Bạn không ở trạng thái Đảo hoang.');
        }
        $dice = DiceService::roll(2, 6);
        $state = $room->state;
        $state['dice'] = $dice;
        $room->state = $state;
        if ($dice[0] === $dice[1]) {
            GameRules::update($room, $player->seat, ['island_turns' => 0, 'island_failures' => 0]);
            $this->clearPending($room);
            $from = $player->position;
            $player->position = ($from + array_sum($dice)) % 32;
            $player->save();
            $this->resolveLanding($room, $player);
        } else {
            $stats = GameRules::stats($room, $player->seat);
            $failures = $stats['island_failures'] + 1;
            GameRules::update($room, $player->seat, ['island_failures' => $failures, 'island_turns' => $failures >= 3 ? 0 : 1]);
            $this->advanceTurn($room);
        }
    }

    private function escapeCard(Room $room, Player $player): void
    {
        $stats = GameRules::stats($room, $player->seat);
        if (($room->state['pending']['action'] ?? '') !== 'island' || $stats['used_saved_card']) {
            $this->invalid('Không thể dùng thẻ lúc này.');
        }
        $at = array_search('island_escape', $stats['hand'], true);
        if ($at === false) {
            $this->invalid('Bạn không có Vé thoát đảo.');
        }
        $hand = $stats['hand'];
        array_splice($hand, $at, 1);
        GameRules::update($room, $player->seat, ['hand' => $hand, 'used_saved_card' => true, 'island_turns' => 0, 'island_failures' => 0]);
        $this->clearPending($room);
        $room->phase = 'PRE_ROLL';
    }

    private function travel(Room $room, Player $player, ?int $target): void
    {
        $pending = $room->state['pending'] ?? [];
        if (($pending['action'] ?? '') !== 'tour' || $target === null || ! in_array($target, $pending['targets'] ?? [], true)) {
            $this->invalid('Điểm đến không hợp lệ.');
        }
        $fee = (int) $room->settings['travel_fee'];
        if ($player->cash < $fee) {
            throw new CapitalRivalsException('insufficient_funds', 'Không đủ phí World Tour.', 422);
        }
        $this->transfer($room, $player, null, $fee, 'world_tour', 24);
        GameRules::update($room, $player->seat, ['tour_waiting' => false]);
        $state = $room->state;
        $state['extra_roll'] = false;
        $state['pending'] = null;
        $room->state = $state;
        $player->position = $target;
        $player->save();
        $this->resolveLanding($room, $player);
    }

    private function championship(Room $room, Player $player, ?int $target, bool $free): void
    {
        $pending = $room->state['pending'] ?? [];
        $expected = $free ? 'chance_target' : 'championship';
        if (($pending['action'] ?? '') !== $expected || $target === null || ! in_array($target, $pending['targets'] ?? [], true)) {
            $this->invalid('Thành phố đăng cai không hợp lệ.');
        }
        $current = $room->state['championship'] ?? null;
        $points = $current && (int) $current['tile'] === $target ? min(9, (int) $current['points'] + 1) : 1;
        if ($current && (int) $current['tile'] === $target && (int) $current['points'] >= 9) {
            $this->invalid('Thành phố đã đạt tối đa 9 điểm đăng cai.');
        }
        if (! $free) {
            $fee = (int) $room->settings['championship_fee'];
            if ($player->cash < $fee) {
                throw new CapitalRivalsException('insufficient_funds', 'Không đủ phí đăng cai.', 422);
            }
            $this->transfer($room, $player, null, $fee, 'championship', $target);
        }
        $state = $room->state;
        $state['championship'] = ['tile' => $target, 'points' => $points];
        $room->state = $state;
        $this->completeLanding($room);
    }

    private function resolveCard(Room $room, Player $player, array $card): void
    {
        $kind = $card['kind'];
        if ($kind === 'credit') {
            $this->transfer($room, null, $player, $card['amount'], 'chance_credit', $player->position);
            $this->completeLanding($room);
        } elseif ($kind === 'debit') {
            if ($this->chargeMandatory($room, $player, $card['amount'], 'chance_fine', null, $player->position, ['kind' => 'complete'])) {
                $this->completeLanding($room);
            }
        } elseif ($kind === 'birthday') {
            $seats = $room->players->where('bankrupt', false)->where('seat', '!=', $player->seat)->sortBy('turn_order')->pluck('seat')->map(fn ($v) => (int) $v)->values()->all();
            $this->birthdayPayments($room, $player, $seats, (int) $card['amount']);
        } elseif ($kind === 'saved') {
            $stats = GameRules::stats($room, $player->seat);
            if (count($stats['hand']) >= 3) {
                $this->pending($room, ['action' => 'discard_card', 'new_card' => $card['card']]);
            } else {
                $hand = $stats['hand'];
                $hand[] = $card['card'];
                GameRules::update($room, $player->seat, ['hand' => $hand]);
                $this->completeLanding($room);
            }
        } elseif ($kind === 'target') {
            $targets = $this->effectTargets($room, $player, $card['effect']);
            if ($targets === []) {
                $this->completeLanding($room);
            } else {
                $this->pending($room, ['action' => 'chance_target', 'effect' => $card['effect'], 'targets' => $targets]);
            }
        } elseif ($kind === 'go_start') {
            $player->position = 0;
            $player->save();
            $this->transfer($room, null, $player, 300000, 'chance_start', 0);
            $this->completeLanding($room);
        } elseif ($kind === 'go_island') {
            $player->position = 8;
            $player->save();
            GameRules::update($room, $player->seat, ['island_turns' => 1, 'island_failures' => 0]);
            $this->advanceTurn($room);
        } elseif ($kind === 'go_world_tour') {
            $player->position = 24;
            $player->save();
            GameRules::update($room, $player->seat, ['tour_waiting' => true]);
            $this->advanceTurn($room);
        } elseif ($kind === 'go_tax') {
            $player->position = 28;
            $player->save();
            $this->resolveLanding($room, $player);
        }
    }

    private function chanceTarget(Room $room, Player $player, ?int $target): void
    {
        $pending = $room->state['pending'] ?? [];
        if (($pending['action'] ?? '') !== 'chance_target' || $target === null || ! in_array($target, $pending['targets'] ?? [], true)) {
            $this->invalid('Mục tiêu thẻ không hợp lệ.');
        }
        $effect = $pending['effect'];
        if ($effect === 'championship') {
            $this->championship($room, $player, $target, true);

            return;
        }
        $state = $room->state;
        if (($state['shields'][$target] ?? false) === true) {
            unset($state['shields'][$target]);
            $room->state = $state;
            $this->completeLanding($room);

            return;
        }
        if ($effect === 'blackout') {
            $state['blackouts'][$target] = $player->seat;
        }
        if ($effect === 'earthquake') {
            $property = $room->properties->firstWhere('tile_index', $target);
            if ($property && $property->level > 0 && $property->level < 4) {
                $property->level = 0;
                $property->save();
            }
        }
        $room->state = $state;
        $this->completeLanding($room);
    }

    private function useShield(Room $room, Player $player, ?int $target): void
    {
        $stats = GameRules::stats($room, $player->seat);
        if ($room->phase !== 'PRE_ROLL' || ($room->state['pending'] ?? null) || $stats['used_saved_card'] || $target === null || ! in_array($target, $this->ownedCities($room, $player->seat), true)) {
            $this->invalid('Không thể đặt lá chắn.');
        }
        $at = array_search('shield', $stats['hand'], true);
        if ($at === false) {
            $this->invalid('Bạn không có Lá chắn.');
        }
        $hand = $stats['hand'];
        array_splice($hand, $at, 1);
        $state = $room->state;
        $state['shields'][$target] = true;
        $room->state = $state;
        GameRules::update($room, $player->seat, ['hand' => $hand, 'used_saved_card' => true]);
    }

    private function discardCard(Room $room, Player $player, ?int $target): void
    {
        $pending = $room->state['pending'] ?? [];
        if (($pending['action'] ?? '') !== 'discard_card') {
            $this->invalid('Không có thẻ cần bỏ.');
        }
        $stats = GameRules::stats($room, $player->seat);
        $hand = $stats['hand'];
        if ($target === null || ! isset($hand[$target])) {
            $this->completeLanding($room);

            return;
        }
        array_splice($hand, $target, 1);
        $hand[] = $pending['new_card'];
        GameRules::update($room, $player->seat, ['hand' => $hand]);
        $this->completeLanding($room);
    }

    private function chargeMandatory(Room $room, Player $payer, int $amount, string $reason, ?int $creditorSeat, ?int $tile, array $continuation): bool
    {
        if ($amount <= 0) {
            return true;
        }
        if ($payer->cash >= $amount) {
            $creditor = $creditorSeat === null ? null : $room->players->firstWhere('seat', $creditorSeat);
            $this->transfer($room, $payer, $creditor, $amount, $reason, $tile);

            return true;
        }
        $state = $room->state;
        $state['pending_debt'] = ['payer_seat' => $payer->seat, 'creditor_seat' => $creditorSeat, 'amount' => $amount, 'reason' => $reason, 'tile' => $tile, 'continuation' => $continuation];
        $state['pending'] = ['action' => 'debt', 'amount' => $amount, 'reason' => $reason, 'payer_seat' => $payer->seat];
        $room->state = $state;
        $room->phase = 'DEBT_RESOLUTION';
        $room->current_seat = $payer->seat;
        $room->turn_deadline = now()->addSeconds((int) $room->settings['debt_timeout']);

        return false;
    }

    private function settleDebt(Room $room, Player $payer): void
    {
        $debt = $room->state['pending_debt'] ?? null;
        if (! $debt || (int) $debt['payer_seat'] !== $payer->seat || $payer->cash < (int) $debt['amount']) {
            $this->invalid('Khoản nợ chưa thể thanh toán.');
        }
        $creditor = $debt['creditor_seat'] === null ? null : $room->players->firstWhere('seat', $debt['creditor_seat']);
        $this->transfer($room, $payer, $creditor, (int) $debt['amount'], $debt['reason'], $debt['tile']);
        $state = $room->state;
        $state['pending_debt'] = null;
        $state['pending'] = null;
        $room->state = $state;
        if (($debt['continuation']['kind'] ?? '') === 'after_rent') {
            $property = $room->properties->firstWhere('tile_index', (int) $debt['continuation']['tile']);
            if ($property) {
                $this->afterRent($room, $payer, $property);
            } else {
                $this->completeLanding($room);
            }
        } elseif (($debt['continuation']['kind'] ?? '') === 'birthday') {
            $drawer = $room->players->firstWhere('seat', (int) $debt['continuation']['drawer_seat']);
            if (! $drawer || $drawer->bankrupt) {
                $this->advanceTurn($room);

                return;
            }
            $room->current_seat = $drawer->seat;
            $this->birthdayPayments($room, $drawer, $debt['continuation']['remaining'] ?? [], (int) $debt['amount']);
        } else {
            $this->completeLanding($room);
        }
    }

    /** @param list<int> $seats */
    private function birthdayPayments(Room $room, Player $drawer, array $seats, int $amount): void
    {
        while ($seats !== []) {
            $seat = (int) array_shift($seats);
            $payer = $room->players->firstWhere('seat', $seat);
            if (! $payer || $payer->bankrupt) {
                continue;
            }
            if (! $this->chargeMandatory($room, $payer, $amount, 'birthday', $drawer->seat, $drawer->position, ['kind' => 'birthday', 'drawer_seat' => $drawer->seat, 'remaining' => array_values($seats)])) {
                return;
            }
        }
        $room->current_seat = $drawer->seat;
        $this->completeLanding($room);
    }

    private function liquidate(Room $room, Player $player, ?int $target): void
    {
        if (($room->state['pending']['action'] ?? '') !== 'debt' || $target === null) {
            $this->invalid('Không thể thanh lý lúc này.');
        }
        $property = $room->properties->firstWhere('tile_index', $target);
        if (! $property || $property->owner_seat !== $player->seat) {
            $this->invalid('Bạn không sở hữu tài sản này.');
        }
        $tile = $this->tile($room, $target);
        $amount = (int) floor($this->value($tile, $property->level, $room) * .5);
        $property->delete();
        $this->clearEffects($room, $target);
        $this->transfer($room, null, $player, $amount, 'liquidation', $target);
        $room->load('properties');
        if ($this->checkVictory($room, $player)) {
            return;
        }
        if ($player->cash >= (int) $room->state['pending_debt']['amount']) {
            $this->settleDebt($room, $player);
        }
    }

    private function eliminate(Room $room, Player $player, bool $bankrupt, ?int $creditorSeat): void
    {
        $debt = $room->state['pending_debt'] ?? null;
        $creditor = $creditorSeat === null ? null : $room->players->firstWhere('seat', $creditorSeat);
        if ($bankrupt && $creditor && $player->cash > 0) {
            $this->transfer($room, $player, $creditor, $player->cash, 'bankruptcy_cash', null);
        } elseif ($player->cash > 0) {
            $this->transfer($room, $player, null, $player->cash, 'surrender_cash', null);
        }
        foreach ($room->properties->where('owner_seat', $player->seat) as $property) {
            $this->clearEffects($room, $property->tile_index);
            $property->delete();
        }
        $player->bankrupt = true;
        $player->save();
        $state = $room->state;
        $state['eliminated'][] = ['seat' => $player->seat, 'at' => now()->toIso8601String(), 'reason' => $bankrupt ? 'bankrupt' : 'surrender'];
        $room->state = $state;
        $room->load('properties');
        if (($debt['continuation']['kind'] ?? '') === 'birthday' && $room->players->where('bankrupt', false)->count() > 1) {
            $drawer = $room->players->firstWhere('seat', (int) $debt['continuation']['drawer_seat']);
            if ($drawer && ! $drawer->bankrupt) {
                $state = $room->state;
                $state['pending'] = null;
                $state['pending_debt'] = null;
                $room->state = $state;
                $room->current_seat = $drawer->seat;
                $this->birthdayPayments($room, $drawer, $debt['continuation']['remaining'] ?? [], (int) $debt['amount']);

                return;
            }
        }
        $this->advanceTurn($room);
    }

    private function timeout(Room $room): void
    {
        $player = $room->players->firstWhere('seat', $room->current_seat);
        if (! $player) {
            return;
        }
        $action = $room->state['pending']['action'] ?? null;
        if ($action === 'debt') {
            while ($player->cash < (int) $room->state['pending_debt']['amount']) {
                $owned = $room->properties->where('owner_seat', $player->seat)->sortBy(fn (BoardProperty $p) => [$this->liquidationValue($room, $p), $p->tile_index])->first();
                if (! $owned) {
                    $this->eliminate($room, $player, true, $room->state['pending_debt']['creditor_seat'] ?? null);

                    return;
                }
                $this->liquidate($room, $player, $owned->tile_index);
                $room->load('properties');
                if (($room->state['pending']['action'] ?? '') !== 'debt') {
                    return;
                }
            }
            $this->settleDebt($room, $player);

            return;
        }
        if ($action === 'island') {
            $this->islandRoll($room, $player);

            return;
        }
        if ($action === 'tour') {
            $this->skipDirect($room, $player);
            $this->performRoll($room, $player, false);

            return;
        }
        if ($action === 'chance_target') {
            $this->chanceTarget($room, $player, min($room->state['pending']['targets']));

            return;
        }
        if ($action === 'discard_card') {
            $this->completeLanding($room);

            return;
        }
        if (in_array($action, ['buy', 'build', 'buyout', 'championship'], true)) {
            $this->completeLanding($room);

            return;
        }
        if ($action === 'card') {
            $this->resolveCard($room, $player, ChanceDeck::drawSpec());

            return;
        }
        $this->performRoll($room, $player, false);
    }

    private function skipDirect(Room $room, Player $player): void
    {
        GameRules::update($room, $player->seat, ['tour_waiting' => false]);
        $this->clearPending($room);
        $room->phase = 'PRE_ROLL';
    }

    private function rent(Room $room, array $tile, BoardProperty $property): int
    {
        return GameRules::rentAmount($room, $tile, $property);
    }

    private function checkVictory(Room $room, Player $player): bool
    {
        $reason = GameRules::monopoly($room, $player->seat);
        if ($reason) {
            $this->finish($room, $player, $reason);

            return true;
        }

        return false;
    }

    private function finish(Room $room, Player $winner, string $reason): void
    {
        $state = $room->state;
        $state['winner_seat'] = $winner->seat;
        $state['win_reason'] = $reason;
        $state['pending'] = null;
        $room->state = $state;
        $room->status = MatchStatus::FINISHED;
        $room->phase = MatchStatus::FINISHED;
        $room->finished_at = now();
        $room->turn_deadline = null;
    }

    private function finishByTime(Room $room): void
    {
        $ranked = $room->players->where('bankrupt', false)->map(fn (Player $player): array => [
            'seat' => $player->seat, 'net_worth' => GameRules::netWorth($room, $player),
        ])->sortByDesc('net_worth')->values();
        $top = (int) ($ranked->first()['net_worth'] ?? 0);
        $winners = $ranked->where('net_worth', $top)->pluck('seat')->values()->all();
        $state = $room->state;
        $state['winner_seat'] = $winners[0] ?? null;
        $state['winner_seats'] = $winners;
        $state['win_reason'] = 'time_limit';
        $state['rankings'] = $ranked->all();
        $state['pending'] = null;
        $room->state = $state;
        $room->status = MatchStatus::FINISHED;
        $room->phase = MatchStatus::FINISHED;
        $room->finished_at = now();
        $room->turn_deadline = null;
    }

    private function pending(Room $room, array $pending): void
    {
        $state = $room->state;
        $state['pending'] = $pending;
        $room->state = $state;
        $room->phase = strtoupper($pending['action']);
        $room->turn_deadline = now()->addSeconds((int) $room->settings['choice_timeout']);
    }

    private function clearPending(Room $room): void
    {
        $state = $room->state;
        $state['pending'] = null;
        $room->state = $state;
    }

    private function transfer(Room $room, ?Player $from, ?Player $to, int $amount, string $type, ?int $tile): void
    {
        if ($amount <= 0) {
            return;
        }
        if ($from) {
            $from->cash -= $amount;
            $from->save();
        }
        if ($to) {
            $to->cash += $amount;
            $to->save();
        }
        $this->ledger($room, $type, $from, $to, $amount, $tile);
    }

    private function ledger(Room $room, string $type, ?Player $from, ?Player $to, int $amount, ?int $tile): void
    {
        LedgerEntry::query()->create(['room_id' => $room->id, 'type' => $type, 'from_player_id' => $from?->id, 'to_player_id' => $to?->id, 'amount' => $amount, 'tile_index' => $tile]);
    }

    private function tile(Room $room, int $index): array
    {
        foreach ($room->settings['tiles'] as $tile) {
            if ((int) $tile['index'] === $index) {
                return $tile;
            }
        }
        $this->invalid('Ô bàn cờ không tồn tại.');
    }

    private function value(array $tile, int $level, Room $room): int
    {
        return GameRules::propertyValue($tile, $level, $room->settings);
    }

    private function assetValue(Room $room, Player $player): int
    {
        return $room->properties->where('owner_seat', $player->seat)->sum(fn (BoardProperty $p) => $this->value($this->tile($room, $p->tile_index), $p->level, $room));
    }

    private function liquidationValue(Room $room, BoardProperty $p): int
    {
        return (int) floor($this->value($this->tile($room, $p->tile_index), $p->level, $room) * .5);
    }

    private function ownedCities(Room $room, int $seat): array
    {
        return $room->properties->where('owner_seat', $seat)->filter(fn (BoardProperty $p) => $this->tile($room, $p->tile_index)['type'] === 'property')->pluck('tile_index')->map(fn ($v) => (int) $v)->values()->all();
    }

    private function ownsGroup(Room $room, int $seat, string $group): bool
    {
        $all = collect($room->settings['tiles'])->where('group', $group)->pluck('index')->all();
        $owned = $room->properties->where('owner_seat', $seat)->pluck('tile_index')->all();

        return $all !== [] && array_diff($all, $owned) === [];
    }

    private function tourTargets(Room $room, Player $player): array
    {
        return collect($room->settings['tiles'])->filter(function ($tile) use ($room, $player) {
            if (! in_array($tile['type'], ['property', 'service'], true)) {
                return false;
            } $p = $room->properties->firstWhere('tile_index', $tile['index']);

            return ! $p || $p->owner_seat === $player->seat;
        })->pluck('index')->map(fn ($v) => (int) $v)->values()->all();
    }

    private function effectTargets(Room $room, Player $player, string $effect): array
    {
        if ($effect === 'championship') {
            return $this->ownedCities($room, $player->seat);
        }

        return $room->properties->where('owner_seat', '!=', $player->seat)->filter(function (BoardProperty $p) use ($room, $effect) {
            $tile = $this->tile($room, $p->tile_index);

            return $tile['type'] === 'property' && ($effect !== 'earthquake' || ($p->level > 0 && $p->level < 4));
        })->pluck('tile_index')->map(fn ($v) => (int) $v)->values()->all();
    }

    private function clearEffects(Room $room, int $tile): void
    {
        $state = $room->state;
        unset($state['shields'][$tile], $state['blackouts'][$tile]);
        if (($state['championship']['tile'] ?? null) === $tile) {
            $state['championship'] = null;
        }
        $room->state = $state;
    }

    private function assertTurn(Room $room, Player $player): void
    {
        if ($room->status !== MatchStatus::IN_PROGRESS) {
            $this->invalid('Trận đấu không diễn ra.');
        }
        if ($player->bankrupt || $player->seat !== $room->current_seat) {
            throw new CapitalRivalsException('not_your_turn', 'Chưa đến lượt của bạn.', 422);
        }
    }

    private function invalid(string $message): never
    {
        throw new CapitalRivalsException('invalid_state', $message, 422);
    }

    private function withPlayer(string $code, string $token, callable $callback): array
    {
        return $this->mutate($code, function (Room $room) use ($token, $callback): array {
            $player = $this->rooms->playerByToken($room, $token);
            $callback($room, $player);
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player->fresh());
        });
    }

    private function mutate(string $code, callable $callback): array
    {
        return DB::transaction(function () use ($code, $callback): array {
            return $callback($this->rooms->lockRoom($code));
        }, 3);
    }
}
