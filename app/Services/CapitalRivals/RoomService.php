<?php

namespace App\Services\CapitalRivals;

use App\Exceptions\CapitalRivalsException;
use App\Models\CapitalRivals\BoardProperty;
use App\Models\CapitalRivals\LedgerEntry;
use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;
use App\Support\CapitalRivals\MatchStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoomService
{
    public function create(?string $name, ?int $userId, array $options = []): array
    {
        return DB::transaction(function () use ($name, $userId) {
            $settings = BoardDefinition::settings();
            $room = Room::query()->create([
                'code' => $this->uniqueCode(),
                'status' => MatchStatus::WAITING_FOR_PLAYERS,
                'phase' => MatchStatus::WAITING_FOR_PLAYERS,
                'version' => 0,
                'settings' => $settings,
                'state' => ['dice' => null, 'pending' => null],
            ]);

            $player = $this->addPlayer($room, $name ?: 'Host', $userId, host: true);
            $room->host_player_id = $player->id;
            $room->save();

            return $this->payload($room->fresh(['players', 'properties']), $player, includeToken: true);
        });
    }

    public function join(string $code, ?string $name, ?int $userId, ?string $existingToken): array
    {
        return DB::transaction(function () use ($code, $name, $userId, $existingToken) {
            $room = $this->lockRoom($code);

            if ($existingToken) {
                $mine = $room->players->firstWhere('player_token', $existingToken);
                if ($mine) {
                    (new BoardResizeService)->resize($room);
                    $mine = $room->players->firstWhere('player_token', $existingToken);

                    return $this->payload($room, $mine, includeToken: true);
                }
            }

            $this->assertJoinable($room);
            (new BoardResizeService)->resize($room);
            $player = $this->addPlayer($room, $name ?: 'Player', $userId, host: false);
            $this->refreshLobbyStatus($room);

            return $this->payload($room->fresh(['players', 'properties']), $player, includeToken: true);
        });
    }

    public function ready(string $code, string $token, bool $ready = true): array
    {
        return DB::transaction(function () use ($code, $token, $ready) {
            $room = $this->lockRoom($code);
            $player = $this->playerByToken($room, $token);
            $this->assertLobby($room);

            $player->ready = $ready;
            $player->save();
            $this->refreshLobbyStatus($room);

            return $this->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function start(string $code, string $token): array
    {
        return DB::transaction(function () use ($code, $token) {
            $room = $this->lockRoom($code);
            $player = $this->playerByToken($room, $token);

            if ((int) $room->host_player_id !== (int) $player->id) {
                throw new CapitalRivalsException('forbidden', 'Only the host can start.', 403);
            }

            $this->refreshLobbyStatus($room);
            if ($room->status !== MatchStatus::READY) {
                throw new CapitalRivalsException('invalid_state', 'Room is not ready to start.');
            }

            $cash = (int) ($room->settings['starting_cash'] ?? 1500);
            $modern = GameRules::enabled($room->settings);
            $v3 = GameRules::isV3($room->settings);
            $fee = $modern && ! $v3 ? (int) ($room->settings['entry_fee'] ?? 0) : 0;
            if ($fee < 0 || $fee >= $cash) {
                throw new CapitalRivalsException('invalid_state', __('messages.cr_invalid_stake'));
            }
            $stats = [];
            $timeout = (int) ($room->settings['turn_timeout'] ?? 30);
            $order = $room->players->pluck('id')->shuffle()->values();

            foreach ($order as $turn => $id) {
                Player::query()->whereKey($id)->update([
                    'turn_order' => $turn,
                    'cash' => $cash - $fee,
                    'position' => 0,
                    'bankrupt' => false,
                ]);
                $seat = (int) Player::query()->whereKey($id)->value('seat');
                $stats[$seat] = $v3
                    ? ['laps' => 0, 'gold' => 0, 'island_turns' => 0, 'island_failures' => 0, 'tour_waiting' => false, 'hand' => [], 'used_saved_card' => false, 'stake' => 0, 'winnings' => 0]
                    : ['laps' => 0, 'gold' => (int) ($room->settings['starting_gold'] ?? 15), 'island_turns' => 0, 'stake' => $fee, 'winnings' => 0];
                if ($fee > 0) {
                    LedgerEntry::query()->create(['room_id' => $room->id, 'type' => 'stake', 'from_player_id' => $id, 'amount' => $fee]);
                }
            }

            $first = Player::query()->findOrFail($order[0]);
            $room->status = MatchStatus::IN_PROGRESS;
            $room->phase = $v3 ? 'PRE_ROLL' : MatchStatus::WAITING_FOR_ACTION;
            $room->current_seat = $first->seat;
            $room->turn_deadline = now()->addSeconds($v3 ? (int) ($room->settings['roll_timeout'] ?? 15) : $timeout);
            $room->started_at = now();
            $room->version++;
            $room->state = [
                'dice' => null,
                'pending' => null,
                'winner_seat' => null,
                'turns_played' => 0,
                'win_reason' => null,
                'chance_draw' => $v3 ? [] : ChanceDeck::shuffled($modern),
                'last_card' => null,
                'player_stats' => $stats,
                'pot' => $fee * count($order),
                'festivals' => $modern ? GameRules::festivals($room->settings['tiles'], $v3 ? 3 : 2) : [],
                'turn_id' => 1,
                'doubles_streak' => 0,
                'championship' => null,
                'shields' => [],
                'blackouts' => [],
                'pending_debt' => null,
                'eliminated' => [],
                'match_deadline' => $v3 ? now()->addSeconds((int) ($room->settings['match_duration'] ?? 1800))->toIso8601String() : null,
            ];
            $room->save();

            return $this->payload($room->fresh(['players', 'properties']), $player);
        });
    }

    public function leave(string $code, string $token): array
    {
        return DB::transaction(function () use ($code, $token) {
            $room = $this->lockRoom($code);
            $player = $this->playerByToken($room, $token);

            if ($room->status === MatchStatus::IN_PROGRESS) {
                throw new CapitalRivalsException('invalid_state', 'Cannot leave a match in progress.');
            }

            $wasHost = (int) $room->host_player_id === (int) $player->id;
            $player->delete();
            $room->load('players');

            if ($room->players->isEmpty()) {
                $room->status = MatchStatus::CANCELLED;
                $room->phase = MatchStatus::CANCELLED;
                $room->host_player_id = null;
                $room->version++;
                $room->save();

                return ['room' => ['code' => $room->code, 'status' => $room->status], 'you' => null, 'players' => []];
            }

            if ($wasHost) {
                $room->host_player_id = $room->players->first()->id;
            }
            $this->refreshLobbyStatus($room);

            return $this->payload($room->fresh(['players', 'properties']), null);
        });
    }

    public function snapshot(string $code, ?string $token): array
    {
        $room = Room::query()->where('code', strtoupper($code))->with(['players', 'properties'])->first();
        if (! $room) {
            throw new CapitalRivalsException('not_found', 'Room not found.', 404);
        }
        $viewer = $token ? $room->players->firstWhere('player_token', $token) : null;

        return $this->payload($room, $viewer);
    }

    private function addPlayer(Room $room, string $name, ?int $userId, bool $host): Player
    {
        $seat = (int) ($room->players()->max('seat') ?? -1) + 1;
        $skins = $room->settings['skins'] ?? ['ace', 'ruby', 'kiwi', 'blaze'];

        return $room->players()->create([
            'seat' => $seat,
            'turn_order' => $seat,
            'name' => Str::limit(trim($name), 32, ''),
            'user_id' => $userId,
            'player_token' => Str::random(40),
            'skin' => $skins[$seat] ?? 'ace',
            'cash' => (int) ($room->settings['starting_cash'] ?? 1500),
            'position' => 0,
            'ready' => $host,
        ]);
    }

    private function refreshLobbyStatus(Room $room): void
    {
        $room->load('players');
        $min = (int) ($room->settings['min_players'] ?? 2);
        $allReady = $room->players->isNotEmpty() && $room->players->every(fn (Player $p) => $p->ready);
        $ready = $room->players->count() >= $min && $allReady;

        $room->status = $ready ? MatchStatus::READY : MatchStatus::WAITING_FOR_PLAYERS;
        $room->phase = $room->status;
        $room->version++;
        $room->save();
    }

    private function assertJoinable(Room $room): void
    {
        if (! in_array($room->status, [MatchStatus::WAITING_FOR_PLAYERS, MatchStatus::READY], true)) {
            throw new CapitalRivalsException('invalid_state', 'This room cannot be joined.');
        }
        $max = (int) ($room->settings['max_players'] ?? 4);
        if ($room->players->count() >= $max) {
            throw new CapitalRivalsException('room_full', 'Room is full.');
        }
    }

    private function assertLobby(Room $room): void
    {
        if (! in_array($room->status, [MatchStatus::WAITING_FOR_PLAYERS, MatchStatus::READY], true)) {
            throw new CapitalRivalsException('invalid_state', 'Match already started.');
        }
    }

    public function lockRoom(string $code): Room
    {
        $room = Room::query()->where('code', strtoupper($code))->lockForUpdate()->first();
        if (! $room) {
            throw new CapitalRivalsException('not_found', 'Room not found.', 404);
        }
        $room->load(['players', 'properties']);

        return $room;
    }

    public function playerByToken(Room $room, string $token): Player
    {
        $player = $room->players->firstWhere('player_token', $token);
        if (! $player) {
            throw new CapitalRivalsException('forbidden', 'Invalid player token.', 403);
        }

        return $player;
    }

    private function uniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Room::query()->where('code', $code)->exists());

        return $code;
    }

    public function payload(Room $room, ?Player $viewer, bool $includeToken = false): array
    {
        $modern = GameRules::enabled($room->settings);
        $v3 = GameRules::isV3($room->settings);
        $players = $room->players->map(fn (Player $p) => [
            'id' => $p->id,
            'seat' => $p->seat,
            'turn_order' => $p->turn_order,
            'name' => $p->name,
            'skin' => $p->skin,
            'cash' => $p->cash,
            'position' => $p->position,
            'ready' => $p->ready,
            'bankrupt' => $p->bankrupt,
            'is_host' => (int) $room->host_player_id === (int) $p->id,
            'laps' => GameRules::stats($room, (int) $p->seat)['laps'],
            'gold' => $modern && ! $v3 ? GameRules::stats($room, (int) $p->seat)['gold'] : 0,
            'island_turns' => GameRules::stats($room, (int) $p->seat)['island_turns'],
            'winnings' => GameRules::stats($room, (int) $p->seat)['winnings'],
            'net_worth' => GameRules::netWorth($room, $p),
        ])->values();

        $pending = $room->state['pending'] ?? null;
        $inMatch = $room->status === MatchStatus::IN_PROGRESS;
        $isTurn = $viewer
            && $inMatch
            && ! $viewer->bankrupt
            && (int) $viewer->seat === (int) $room->current_seat;
        $canBuy = $isTurn && ($pending['action'] ?? null) === 'buy';
        $canPay = $isTurn && ($pending['action'] ?? null) === 'pay';
        $canDraw = $isTurn && ($pending['action'] ?? null) === 'card';
        $canUpgrade = $isTurn && ($modern ? ($pending['action'] ?? '') === 'upgrade' : $room->phase === MatchStatus::WAITING_FOR_ACTION && ! $pending);
        $upgrades = $canUpgrade && $viewer
            ? $this->upgradeOptions($room, $viewer)
            : [];
        $purchaseOptions = [];
        if ($canBuy) {
            foreach ($room->settings['tiles'] ?? [] as $tile) {
                if ((int) $tile['index'] === (int) $pending['tile']) {
                    if ($v3) {
                        $max = GameRules::stats($room, (int) $viewer->seat)['laps'] > 0 ? 3 : 2;
                        $levels = ($tile['type'] ?? '') === 'property' ? range(0, $max) : [0];
                        $purchaseOptions = array_map(fn (int $level) => ['level' => $level, 'price' => GameRules::propertyValue($tile, $level, $room->settings)], $levels);
                    } else {
                        $purchaseOptions = HousePurchase::options($tile, $room->settings, (int) GameRules::stats($room, (int) $viewer->seat)['laps']);
                    }
                    break;
                }
            }
        }

        $you = null;
        if ($viewer) {
            $you = [
                'id' => $viewer->id,
                'seat' => $viewer->seat,
                'is_host' => (int) $room->host_player_id === (int) $viewer->id,
                'is_turn' => $isTurn,
                'can_roll' => $isTurn && $room->phase === MatchStatus::WAITING_FOR_ACTION && ! $pending && (! $modern || GameRules::stats($room, (int) $viewer->seat)['island_turns'] === 0),
                'can_confirm_roll' => $isTurn && $modern && ($pending['action'] ?? '') === 'roll_choice',
                'can_reroll' => $isTurn && $modern && ($pending['action'] ?? '') === 'roll_choice' && GameRules::stats($room, (int) $viewer->seat)['gold'] >= (int) ($room->settings['reroll_cost'] ?? 3),
                'can_wait_island' => $isTurn && $modern && ! $pending && GameRules::stats($room, (int) $viewer->seat)['island_turns'] > 0,
                'can_escape_island' => $isTurn && $modern && ! $pending && GameRules::stats($room, (int) $viewer->seat)['island_turns'] > 0 && $viewer->cash >= (int) ($room->settings['island_fee'] ?? 200000),
                'can_choose_destination' => $isTurn && $modern && in_array($pending['action'] ?? '', ['travel', 'festival'], true),
                'can_takeover' => $isTurn && $modern && ($pending['action'] ?? '') === 'takeover',
                'can_accept_winnings' => $isTurn && $modern && ! $pending && (int) ($room->settings['winnings_target'] ?? 0) > 0 && GameRules::netWorth($room, $viewer) >= (int) $room->settings['winnings_target'],
                'can_buy' => $canBuy,
                'can_skip' => $canBuy || ($isTurn && $modern && in_array($pending['action'] ?? '', ['travel', 'festival', 'upgrade', 'takeover'], true)),
                'can_pay' => $canPay,
                'can_draw' => $canDraw,
                'can_upgrade' => $canUpgrade && $upgrades !== [],
                'can_end_turn' => $isTurn && ($canBuy || ($room->phase === MatchStatus::WAITING_FOR_ACTION && ! $pending)) && (! $modern || GameRules::stats($room, (int) $viewer->seat)['island_turns'] === 0),
                'can_bankrupt' => $isTurn,
                'upgrades' => $upgrades,
                'purchase_options' => $purchaseOptions,
                'max_initial_level' => GameRules::maxInitialLevel($room->settings, (int) GameRules::stats($room, (int) $viewer->seat)['laps']),
            ];
            if ($v3) {
                $action = $pending['action'] ?? null;
                $stats = GameRules::stats($room, (int) $viewer->seat);
                $you = [...$you,
                    'can_roll' => $isTurn && ! $pending && in_array($room->phase, ['PRE_ROLL', MatchStatus::WAITING_FOR_ACTION], true),
                    'can_confirm_roll' => false, 'can_reroll' => false,
                    'can_wait_island' => $isTurn && $action === 'island',
                    'can_escape_island' => $isTurn && $action === 'island' && $viewer->cash >= (int) ($room->settings['island_fee'] ?? 200000),
                    'can_choose_destination' => $isTurn && in_array($action, ['tour', 'championship', 'chance_target'], true),
                    'can_takeover' => $isTurn && $action === 'buyout',
                    'can_accept_winnings' => false,
                    'can_buy' => $isTurn && $action === 'buy',
                    'can_skip' => $isTurn && in_array($action, ['buy', 'build', 'buyout', 'championship', 'tour', 'discard_card'], true),
                    'can_pay' => $isTurn && $action === 'debt' && $viewer->cash >= (int) ($pending['amount'] ?? PHP_INT_MAX),
                    'can_draw' => $isTurn && $action === 'card',
                    'can_upgrade' => $isTurn && $action === 'build',
                    'can_end_turn' => false,
                    'can_bankrupt' => $isTurn && $action === 'debt',
                    'can_island_roll' => $isTurn && $action === 'island',
                    'can_use_escape_card' => $isTurn && $action === 'island' && ! $stats['used_saved_card'] && in_array('island_escape', $stats['hand'], true),
                    'can_use_shield' => $isTurn && ! $stats['used_saved_card'] && in_array('shield', $stats['hand'], true),
                    'hand' => $stats['hand'],
                    'targets' => $pending['targets'] ?? [],
                    'upgrades' => $action === 'build' ? (function () use ($room, $pending): array {
                        $property = $room->properties->firstWhere('tile_index', (int) $pending['tile']);
                        $next = (int) (($property?->level ?? 0) + 1);
                        $tile = collect($room->settings['tiles'])->firstWhere('index', (int) $pending['tile']);

                        return [['tile' => (int) $pending['tile'], 'level' => $next, 'cost' => (int) ($next === 4 ? ($tile['hotel_price'] ?? 0) : ($tile['house_price'] ?? 0))]];
                    })() : [],
                    'max_initial_level' => $stats['laps'] > 0 ? 3 : 2,
                ];
            }
            if ($includeToken) {
                $you['player_token'] = $viewer->player_token;
            }
        }

        $tileDefinitions = array_column($room->settings['tiles'] ?? [], null, 'index');
        $properties = ($room->relationLoaded('properties') ? $room->properties : $room->properties()->get())
            ->map(fn (BoardProperty $p) => [
                'tile_index' => $p->tile_index,
                'owner_seat' => $p->owner_seat,
                'level' => $p->level,
                'rent' => $v3 ? GameRules::rentAmount($room, $tileDefinitions[$p->tile_index] ?? [], $p) : null,
                'festival' => isset($room->state['festivals'][$p->tile_index]),
                'shield' => (bool) ($room->state['shields'][$p->tile_index] ?? false),
                'blackout' => isset($room->state['blackouts'][$p->tile_index]),
                'championship_points' => (int) (($room->state['championship']['tile'] ?? -1) === $p->tile_index ? ($room->state['championship']['points'] ?? 0) : 0),
            ])->values();

        $ledger = LedgerEntry::query()
            ->where('room_id', $room->id)
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(fn (LedgerEntry $row) => [
                'id' => $row->id,
                'type' => $row->type,
                'from_player_id' => $row->from_player_id,
                'to_player_id' => $row->to_player_id,
                'amount' => $row->amount,
                'tile_index' => $row->tile_index,
            ])->values();

        $publicState = $room->state ?? [];
        if ($v3) {
            foreach (($publicState['player_stats'] ?? []) as $seat => $stats) {
                if (! $viewer || (int) $seat !== (int) $viewer->seat) {
                    $publicState['player_stats'][$seat]['hand_count'] = count($stats['hand'] ?? []);
                    unset($publicState['player_stats'][$seat]['hand']);
                }
            }
        }

        return [
            'room' => [
                'code' => $room->code,
                'rules_version' => (int) ($room->settings['rules_version'] ?? 1),
                'entry_fee' => (int) ($room->settings['entry_fee'] ?? 0),
                'pot' => (int) ($room->state['pot'] ?? 0),
                'reroll_cost' => (int) ($room->settings['reroll_cost'] ?? 3),
                'island_fee' => (int) ($room->settings['island_fee'] ?? 200000),
                'travel_fee' => (int) ($room->settings['travel_fee'] ?? 50000),
                'winnings_target' => (int) ($room->settings['winnings_target'] ?? 0),
                'status' => $room->status,
                'phase' => $room->phase,
                'current_seat' => $room->current_seat,
                'turn_deadline' => optional($room->turn_deadline)?->toIso8601String(),
                'version' => $room->version,
                'started_at' => optional($room->started_at)?->toIso8601String(),
                'finished_at' => optional($room->finished_at)?->toIso8601String(),
                'tile_count' => count($room->settings['tiles'] ?? []),
                'starting_cash' => $room->settings['starting_cash'] ?? 1500,
                'turn_timeout' => $room->settings['turn_timeout'] ?? 30,
                'max_turns' => $room->settings['max_turns'] ?? 0,
                'turns_played' => $room->state['turns_played'] ?? 0,
                'min_players' => $room->settings['min_players'] ?? 2,
                'max_players' => $room->settings['max_players'] ?? 4,
                'winner_seat' => $room->state['winner_seat'] ?? null,
                'winner_seats' => $room->state['winner_seats'] ?? (($room->state['winner_seat'] ?? null) === null ? [] : [$room->state['winner_seat']]),
                'win_reason' => $room->state['win_reason'] ?? null,
                'seconds_left' => $inMatch && $room->turn_deadline
                    ? max(0, $room->turn_deadline->getTimestamp() - now()->getTimestamp())
                    : null,
                'match_deadline' => $room->state['match_deadline'] ?? null,
            ],
            'you' => $you,
            'players' => $players,
            'tiles' => $room->settings['tiles'] ?? [],
            'properties' => $properties,
            'ledger' => $ledger,
            'state' => $publicState,
        ];
    }

    /**
     * @return list<array{tile: int, name: string, cost: int, level: int}>
     */
    private function upgradeOptions(Room $room, Player $viewer): array
    {
        if (GameRules::enabled($room->settings)) {
            $pending = $room->state['pending'] ?? [];

            return ($pending['action'] ?? '') === 'upgrade' ? [['tile' => (int) $pending['tile'], 'name' => $pending['name'], 'cost' => (int) $pending['price'], 'level' => (int) $pending['level'] - 1]] : [];
        }
        $tiles = $room->settings['tiles'] ?? [];
        $max = min(4, (int) ($room->settings['max_upgrade_level'] ?? 4));
        $out = [];
        foreach ($room->properties->where('owner_seat', $viewer->seat) as $owned) {
            $tile = null;
            foreach ($tiles as $def) {
                if ((int) ($def['index'] ?? -1) === (int) $owned->tile_index) {
                    $tile = $def;
                    break;
                }
            }
            if (! $tile || ($tile['type'] ?? '') !== 'property') {
                continue;
            }
            if ((int) $owned->level >= $max) {
                continue;
            }
            if (! PropertyUpgrade::ownsGroup((int) $viewer->seat, (string) ($tile['group'] ?? ''), $room->properties, $tiles)) {
                continue;
            }
            $out[] = [
                'tile' => (int) $owned->tile_index,
                'name' => (string) ($tile['name'] ?? ''),
                'cost' => PropertyUpgrade::cost($tile, $room->settings),
                'level' => max(1, (int) $owned->level),
            ];
        }

        return $out;
    }
}
