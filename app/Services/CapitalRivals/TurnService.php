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
    public function __construct(private readonly RoomService $rooms, private readonly SpecTurnService $spec) {}

    public function tick(string $code, ?string $token = null): array
    {
        if ($this->isV3($code)) {
            return $this->spec->tick($code, $token);
        }

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
        if ($this->isV3($code)) {
            return $this->spec->endTurn($code, $token);
        }

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
            if (GameRules::enabled($room->settings) && ! $pending && GameRules::stats($room, (int) $player->seat)['island_turns'] > 0) {
                throw new CapitalRivalsException('invalid_state', __('messages.cr_island_required'));
            }
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
        if ($this->isV3($code)) {
            return $this->spec->bankrupt($code, $token);
        }

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
        if ($this->isV3($code)) {
            return $this->spec->roll($code, $token);
        }

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
            if (GameRules::enabled($room->settings)) {
                if (GameRules::stats($room, (int) $player->seat)['island_turns'] > 0) {
                    throw new CapitalRivalsException('invalid_state', __('messages.cr_island_required'));
                }
                $state = $room->state ?? [];
                $state['dice'] = $dice;
                $room->state = $state;
                $this->setPending($room, ['action' => 'roll_choice', 'origin' => (int) $player->position, 'dice' => $dice]);
                $room->phase = MatchStatus::RESOLVING_TILE;
                $room->version++;
                $room->save();

                return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
            }
            $moved = MovementService::advance((int) $player->position, array_sum($dice), count($room->settings['tiles'] ?? []));
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

    public function buy(string $code, string $token, int $level = 1): array
    {
        if ($this->isV3($code)) {
            return $this->spec->buy($code, $token, $level);
        }

        return $this->mutate($code, function () use ($code, $token, $level) {
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
            $tileIndex = (int) $pending['tile'];
            $tile = $this->tileAt($room, $tileIndex);
            $price = HousePurchase::price($tile, $level, $room->settings, (int) GameRules::stats($room, (int) $player->seat)['laps']);
            if ((int) $player->cash < $price) {
                throw new CapitalRivalsException('insufficient_funds', 'Not enough cash to buy.');
            }
            if ($room->properties->firstWhere('tile_index', $tileIndex)) {
                throw new CapitalRivalsException('invalid_state', 'Tile already owned.');
            }

            $this->debit($room, $player, $price, 'buy', $tileIndex, null);
            $room->properties()->create([
                'tile_index' => $tileIndex,
                'owner_seat' => $player->seat,
                'level' => ($tile['type'] ?? '') === 'property' ? $level : 0,
            ]);
            $this->advanceTurn($room);
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function skip(string $code, string $token): array
    {
        if ($this->isV3($code)) {
            return $this->spec->skip($code, $token);
        }

        return $this->mutate($code, function () use ($code, $token) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($expired = $this->payloadIfExpired($room, $player)) {
                return $expired;
            }
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? null;
            if (! in_array($pending['action'] ?? null, GameRules::enabled($room->settings) ? ['buy', 'upgrade', 'travel', 'festival', 'takeover'] : ['buy'], true)) {
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
        if ($this->isV3($code)) {
            return $this->spec->pay($code, $token);
        }

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
            if ($room->status !== MatchStatus::FINISHED && ! $this->offerTakeover($room, $player, $pending)) {
                $this->advanceTurn($room);
            }
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function card(string $code, string $token): array
    {
        if ($this->isV3($code)) {
            return $this->spec->card($code, $token);
        }

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
        if ($this->isV3($code)) {
            return $this->spec->upgrade($code, $token, $tileIndex);
        }

        return $this->mutate($code, function () use ($code, $token, $tileIndex) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($expired = $this->payloadIfExpired($room, $player)) {
                return $expired;
            }
            $this->assertTurn($room, $player);
            $pending = $room->state['pending'] ?? null;
            $modern = GameRules::enabled($room->settings);
            $valid = $modern
                ? ($pending['action'] ?? null) === 'upgrade' && (int) ($pending['tile'] ?? -1) === $tileIndex && (int) $player->position === $tileIndex
                : $room->phase === MatchStatus::WAITING_FOR_ACTION && ! $pending;
            if (! $valid) {
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
            $max = min(4, (int) ($room->settings['max_upgrade_level'] ?? 4));
            if ((int) $owned->level >= $max) {
                throw new CapitalRivalsException('invalid_state', 'Already at max level.');
            }
            $group = (string) ($tile['group'] ?? '');
            if (! $modern && ! PropertyUpgrade::ownsGroup((int) $player->seat, $group, $room->properties, $room->settings['tiles'] ?? [])) {
                throw new CapitalRivalsException('invalid_state', 'Own the full group before upgrading.');
            }
            if ($modern && max(1, (int) $owned->level) + 1 >= 3 && GameRules::stats($room, (int) $player->seat)['laps'] < 1) {
                throw new CapitalRivalsException('invalid_state', __('messages.cr_lap_required'));
            }
            $cost = PropertyUpgrade::cost($tile, $room->settings);
            if ((int) $player->cash < $cost) {
                throw new CapitalRivalsException('insufficient_funds', 'Not enough cash to upgrade.');
            }
            $this->debit($room, $player, $cost, 'upgrade', $tileIndex, null);
            $owned->level = max(1, (int) $owned->level) + 1;
            $owned->save();
            if ($modern) {
                $this->advanceTurn($room);
            }
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function action(string $code, string $token, string $action, ?int $target = null): array
    {
        if ($this->isV3($code)) {
            return $this->spec->action($code, $token, $action, $target);
        }

        return $this->mutate($code, function () use ($code, $token, $action, $target) {
            $room = $this->rooms->lockRoom($code);
            $player = $this->rooms->playerByToken($room, $token);
            if ($expired = $this->payloadIfExpired($room, $player)) {
                return $expired;
            }
            $this->assertTurn($room, $player);
            if (! GameRules::enabled($room->settings)) {
                throw new CapitalRivalsException('invalid_state', __('messages.cr_action_unavailable'));
            }
            $pending = $room->state['pending'] ?? null;
            $stats = GameRules::stats($room, (int) $player->seat);
            if (in_array($action, ['confirm_roll', 'reroll'], true)) {
                if (($pending['action'] ?? '') !== 'roll_choice') {
                    throw new CapitalRivalsException('invalid_state', __('messages.cr_action_unavailable'));
                }
                if ($action === 'reroll') {
                    $cost = (int) ($room->settings['reroll_cost'] ?? 3);
                    if ($stats['gold'] < $cost) {
                        throw new CapitalRivalsException('insufficient_gold', __('messages.cr_insufficient_gold'));
                    }
                    GameRules::update($room, (int) $player->seat, ['gold' => $stats['gold'] - $cost]);
                    $dice = DiceService::roll((int) ($room->settings['dice_count'] ?? 2), (int) ($room->settings['dice_sides'] ?? 6));
                    $state = $room->state;
                    $state['dice'] = $dice;
                    $state['pending']['dice'] = $dice;
                    $room->state = $state;
                    $this->ledger($room, 'reroll', $player->id, null, $cost, null);
                } else {
                    $this->commitRoll($room, $player, $pending['dice']);
                }
            } elseif (in_array($action, ['escape_island', 'wait_island'], true)) {
                if ($pending || $stats['island_turns'] < 1) {
                    throw new CapitalRivalsException('invalid_state', __('messages.cr_action_unavailable'));
                }
                if ($action === 'wait_island') {
                    GameRules::update($room, (int) $player->seat, ['island_turns' => $stats['island_turns'] - 1]);
                    $this->ledger($room, 'island_wait', $player->id, null, 0, (int) $player->position);
                    $this->advanceTurn($room);
                } else {
                    $fee = (int) ($room->settings['island_fee'] ?? 200000);
                    if ($player->cash < $fee) {
                        throw new CapitalRivalsException('insufficient_funds', __('messages.cr_insufficient_cash'));
                    }
                    $this->debit($room, $player, $fee, 'island_exit', (int) $player->position, null);
                    GameRules::update($room, (int) $player->seat, ['island_turns' => 0]);
                }
            } elseif (in_array($action, ['travel', 'festival'], true)) {
                if (($pending['action'] ?? '') !== $action || $target === null || $target === (int) $player->position) {
                    throw new CapitalRivalsException('invalid_state', __('messages.cr_action_unavailable'));
                }
                $tile = $this->tileAt($room, $target);
                if ($action === 'festival') {
                    if (($tile['type'] ?? '') !== 'property') {
                        throw new CapitalRivalsException('invalid_state', __('messages.cr_city_required'));
                    }
                    $state = $room->state;
                    $state['festivals'][$target] = min(5, (int) ($state['festivals'][$target] ?? 1) + 1);
                    $room->state = $state;
                    $this->ledger($room, 'festival', $player->id, null, 0, $target);
                    $this->advanceTurn($room);
                } else {
                    $fee = (int) ($room->settings['travel_fee'] ?? 50000);
                    if ($player->cash < $fee) {
                        throw new CapitalRivalsException('insufficient_funds', __('messages.cr_insufficient_cash'));
                    }
                    $this->debit($room, $player, $fee, 'travel', (int) $player->position, null);
                    $player->position = $target;
                    $player->save();
                    $state = $room->state;
                    $state['pending'] = null;
                    $room->state = $state;
                    $this->resolveLanding($room, $player);
                }
            } elseif (in_array($action, ['takeover', 'decline_takeover'], true)) {
                if (($pending['action'] ?? '') !== 'takeover') {
                    throw new CapitalRivalsException('invalid_state', __('messages.cr_action_unavailable'));
                }
                if ($action === 'takeover') {
                    $tile = $this->tileAt($room, (int) $pending['tile']);
                    $owned = $room->properties->firstWhere('tile_index', (int) $tile['index']);
                    $owner = $owned ? $room->players->firstWhere('seat', $owned->owner_seat) : null;
                    if (! $owned || ! $owner || $owned->owner_seat === $player->seat || $owned->level >= 4 || ($tile['type'] ?? '') !== 'property') {
                        throw new CapitalRivalsException('invalid_state', __('messages.cr_hotel_protected'));
                    }
                    $price = 2 * GameRules::propertyValue($tile, (int) $owned->level, $room->settings);
                    if ($player->cash < $price) {
                        throw new CapitalRivalsException('insufficient_funds', __('messages.cr_insufficient_cash'));
                    }
                    $this->transfer($room, $player, $owner, $price, 'takeover', (int) $tile['index']);
                    $owned->owner_seat = $player->seat;
                    $owned->save();
                }
                $this->advanceTurn($room);
            } elseif ($action === 'accept_winnings') {
                $targetWorth = (int) ($room->settings['winnings_target'] ?? 0);
                if ($pending || $targetWorth <= 0 || GameRules::netWorth($room, $player) < $targetWorth) {
                    throw new CapitalRivalsException('invalid_state', __('messages.cr_target_not_met'));
                }
                $this->finishMatch($room, (int) $player->seat, 'accept_winnings', $room->state ?? []);
            } else {
                throw new CapitalRivalsException('invalid_state', __('messages.cr_action_unavailable'));
            }
            $room->version++;
            $room->save();

            return $this->rooms->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    private function commitRoll(Room $room, Player $player, array $dice): void
    {
        $moved = MovementService::advance((int) $player->position, array_sum($dice), count($room->settings['tiles']));
        $state = $room->state ?? [];
        $state['pending'] = null;
        $room->state = $state;
        $player->position = $moved['position'];
        if ($moved['laps'] > 0) {
            $stats = GameRules::stats($room, (int) $player->seat);
            GameRules::update($room, (int) $player->seat, ['laps' => $stats['laps'] + $moved['laps']]);
            $this->credit($room, $player, (int) ($room->settings['start_reward'] ?? 300000) * $moved['laps'], 'start_bonus', 0);
        }
        $player->save();
        $room->phase = MatchStatus::RESOLVING_TILE;
        $this->resolveLanding($room, $player);
    }

    private function offerTakeover(Room $room, Player $player, array $pending): bool
    {
        if (! GameRules::enabled($room->settings) || $player->bankrupt || ($pending['kind'] ?? '') !== 'rent') {
            return false;
        }
        $tile = $this->tileAt($room, (int) $pending['tile']);
        $owned = $room->properties->firstWhere('tile_index', (int) $tile['index']);
        if (! $owned || ($tile['type'] ?? '') !== 'property' || (int) $owned->level >= 4) {
            return false;
        }
        $price = 2 * GameRules::propertyValue($tile, (int) $owned->level, $room->settings);
        $this->setPending($room, ['action' => 'takeover', 'tile' => (int) $tile['index'], 'name' => $tile['name'], 'price' => $price]);
        $room->phase = MatchStatus::RESOLVING_TILE;

        return true;
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

    private function islandPosition(Room $room): int
    {
        foreach ($room->settings['tiles'] ?? [] as $tile) {
            if (($tile['special'] ?? '') === 'island' || ($tile['type'] ?? '') === 'detention') {
                return (int) $tile['index'];
            }
        }

        return 0;
    }

    private function resolveLanding(Room $room, Player $player): void
    {
        $tile = $this->tileAt($room, (int) $player->position);
        $type = $tile['type'] ?? 'rest';
        $modern = GameRules::enabled($room->settings);
        if ($modern && (($tile['special'] ?? '') === 'island' || $type === 'detention')) {
            GameRules::update($room, (int) $player->seat, ['island_turns' => (int) ($room->settings['island_turns'] ?? 3)]);
            $this->advanceTurn($room);

            return;
        }
        if ($modern && in_array($tile['special'] ?? '', ['airport', 'championship', 'world_tour'], true)) {
            $this->setPending($room, ['action' => ($tile['special'] ?? '') === 'airport' ? 'travel' : 'festival', 'tile' => (int) $tile['index'], 'name' => $tile['name']]);
            $room->phase = MatchStatus::RESOLVING_TILE;

            return;
        }

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
                if ($modern && $type === 'property' && (int) $owned->level < 4) {
                    $next = max(1, (int) $owned->level) + 1;
                    if ($next <= 2 || GameRules::stats($room, (int) $player->seat)['laps'] >= 1) {
                        $this->setPending($room, ['action' => 'upgrade', 'tile' => (int) $tile['index'], 'name' => $tile['name'], 'level' => $next, 'price' => PropertyUpgrade::cost($tile, $room->settings)]);
                        $room->phase = MatchStatus::RESOLVING_TILE;

                        return;
                    }
                }
                $this->advanceTurn($room);

                return;
            }
            $rent = RentCalculator::amount($tile, (int) $owned->owner_seat, $room->properties, $room->settings['tiles'] ?? []);
            if ($modern) {
                $rent *= max(1, (int) ($room->state['festivals'][$tile['index']] ?? 1));
            }
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
                'amount' => $modern ? (int) ceil(GameRules::netWorth($room, $player) * (float) ($room->settings['tax_rate'] ?? 0.10)) : (int) ($tile['amount'] ?? 0),
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
            $player->position = $this->islandPosition($room);
            $player->save();
            $this->advanceTurn($room);

            return;
        }

        $this->advanceTurn($room);
    }

    private function isV3(string $code): bool
    {
        $room = Room::query()->where('code', strtoupper($code))->first(['settings']);

        return $room && GameRules::isV3($room->settings ?? []);
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
        if ($current && GameRules::enabled($room->settings) && $action === 'roll_choice') {
            $this->commitRoll($room, $current, $pending['dice']);

            return true;
        }
        if ($current && GameRules::enabled($room->settings) && ! $pending) {
            $stats = GameRules::stats($room, (int) $current->seat);
            if ($stats['island_turns'] > 0) {
                GameRules::update($room, (int) $current->seat, ['island_turns' => $stats['island_turns'] - 1]);
            }
        }
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
        $room->load(['players', 'properties']);
        $alive = $room->players->where('bankrupt', false)->sortBy('turn_order')->values();
        $state = $room->state ?? [];
        $state['pending'] = null;
        $state['turns_played'] = (int) ($state['turns_played'] ?? 0) + 1;

        if ($alive->count() <= 1) {
            $this->finishMatch($room, $alive->first()?->seat, 'last_standing', $state);

            return;
        }
        if (GameRules::enabled($room->settings)) {
            foreach ($alive as $contender) {
                if ($reason = GameRules::monopoly($room, (int) $contender->seat)) {
                    $this->finishMatch($room, (int) $contender->seat, $reason, $state);

                    return;
                }
            }
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
        if (GameRules::enabled($room->settings) && $winnerSeat !== null && empty($state['pot_paid'])) {
            $winner = $room->players->firstWhere('seat', $winnerSeat);
            $pot = (int) ($state['pot'] ?? 0);
            if ($winner && $pot > 0) {
                $this->credit($room, $winner, $pot, 'winnings', null);
                $state['player_stats'][$winnerSeat]['winnings'] = (int) ($state['player_stats'][$winnerSeat]['winnings'] ?? 0) + $pot;
            }
            $state['pot_paid'] = true;
            $state['pot'] = 0;
        }
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
        if (GameRules::enabled($room->settings)) {
            $room->turn_deadline = now()->addSeconds((int) ($room->settings['turn_timeout'] ?? 30));
        }
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
        $cashBefore = (int) $player->cash;
        $positionBefore = (int) $player->position;
        $state = $room->state ?? [];
        $modern = GameRules::enabled($room->settings);
        $card = ChanceDeck::draw($state, $modern);
        if ($modern && isset($card['amount'])) {
            $oldAmount = (int) $card['amount'];
            $card['amount'] = $oldAmount * 1000;
            $card['text'] = str_replace('$'.$oldAmount, '$'.number_format($card['amount']), $card['text']);
        }
        if (($card['id'] ?? '') === 'recall_gate') {
            $card['position'] = $this->islandPosition($room);
        }
        if (($card['kind'] ?? '') === 'go_start') {
            $card['amount'] = (int) ($room->settings['start_reward'] ?? 200);
            $card['position'] = 0;
        }
        $state['last_card'] = $card;
        $state['pending'] = null;
        $room->state = $state;

        $kind = $card['kind'] ?? 'credit';
        if ($kind === 'credit') {
            $this->credit($room, $player, (int) ($card['amount'] ?? 0), 'chance', (int) $player->position);
        } elseif ($kind === 'debit') {
            $this->debit($room, $player, (int) ($card['amount'] ?? 0), 'chance', (int) $player->position, null);
        } elseif ($kind === 'move') {
            $player->position = (int) ($card['position'] ?? $this->islandPosition($room));
            $player->save();
            if ($modern && $player->position === $this->islandPosition($room)) {
                GameRules::update($room, (int) $player->seat, ['island_turns' => (int) ($room->settings['island_turns'] ?? 3)]);
            }
        } elseif ($kind === 'go_start') {
            $player->position = 0;
            $player->save();
            $this->credit($room, $player, (int) ($room->settings['start_reward'] ?? 200), 'start_bonus', 0);
        } elseif ($modern && $kind === 'earthquake') {
            foreach ($room->properties as $property) {
                $tile = $this->tileAt($room, (int) $property->tile_index);
                if (($tile['type'] ?? '') === 'property' && $property->level > 1 && $property->level < 4) {
                    $property->level--;
                    $property->save();
                }
            }
        } elseif ($modern && $kind === 'takeover') {
            $candidates = $room->properties->filter(fn ($property) => (int) $property->owner_seat !== (int) $player->seat && (int) $property->level < 4 && ($this->tileAt($room, (int) $property->tile_index)['type'] ?? '') === 'property');
            if ($candidates->isNotEmpty()) {
                $owned = $candidates->random();
                $owned->owner_seat = $player->seat;
                $owned->save();
                $card['tile_index'] = (int) $owned->tile_index;
            }
        } elseif ($modern && $kind === 'festival') {
            $cities = array_values(array_filter($room->settings['tiles'], fn ($tile) => $tile['type'] === 'property'));
            if ($cities !== []) {
                $city = $cities[array_rand($cities)];
                $state = $room->state;
                $state['festivals'][$city['index']] = min(5, (int) ($state['festivals'][$city['index']] ?? 1) + 1);
                $room->state = $state;
                $card['tile_index'] = (int) $city['index'];
            }
        }

        $player->refresh();
        $state = $room->state ?? [];
        $state['last_card'] = [
            ...$card,
            'draw_id' => $room->id.'-'.($room->version + 1),
            'player_name' => $player->name,
            'cash_change' => (int) $player->cash - $cashBefore,
            'position_before' => $positionBefore,
            'position_after' => (int) $player->position,
            'bankrupt' => (bool) $player->bankrupt,
        ];
        $room->state = $state;

        if ($room->status !== MatchStatus::FINISHED) {
            $this->advanceTurn($room);
        }
    }

    /**
     * @template T
     *
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
