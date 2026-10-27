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
    public function create(?string $name, ?int $userId): array
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
                    return $this->payload($room, $mine, includeToken: true);
                }
            }

            $this->assertJoinable($room);
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
            $timeout = (int) ($room->settings['turn_timeout'] ?? 30);
            $order = $room->players->pluck('id')->shuffle()->values();

            foreach ($order as $turn => $id) {
                Player::query()->whereKey($id)->update([
                    'turn_order' => $turn,
                    'cash' => $cash,
                    'position' => 0,
                    'bankrupt' => false,
                ]);
            }

            $first = Player::query()->findOrFail($order[0]);
            $room->status = MatchStatus::IN_PROGRESS;
            $room->phase = MatchStatus::WAITING_FOR_ACTION;
            $room->current_seat = $first->seat;
            $room->turn_deadline = now()->addSeconds($timeout);
            $room->started_at = now();
            $room->version++;
            $room->state = [
                'dice' => null,
                'pending' => null,
                'winner_seat' => null,
                'turns_played' => 0,
                'win_reason' => null,
                'chance_draw' => ChanceDeck::shuffled(),
                'last_card' => null,
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
        $canUpgrade = $isTurn && $room->phase === MatchStatus::WAITING_FOR_ACTION && ! $pending;
        $upgrades = $canUpgrade && $viewer
            ? $this->upgradeOptions($room, $viewer)
            : [];

        $you = null;
        if ($viewer) {
            $you = [
                'id' => $viewer->id,
                'seat' => $viewer->seat,
                'is_host' => (int) $room->host_player_id === (int) $viewer->id,
                'is_turn' => $isTurn,
                'can_roll' => $isTurn && $room->phase === MatchStatus::WAITING_FOR_ACTION && ! $pending,
                'can_buy' => $canBuy,
                'can_skip' => $canBuy,
                'can_pay' => $canPay,
                'can_draw' => $canDraw,
                'can_upgrade' => $canUpgrade && $upgrades !== [],
                'can_end_turn' => $isTurn && ($canBuy || ($room->phase === MatchStatus::WAITING_FOR_ACTION && ! $pending)),
                'can_bankrupt' => $isTurn,
                'upgrades' => $upgrades,
            ];
            if ($includeToken) {
                $you['player_token'] = $viewer->player_token;
            }
        }

        $properties = ($room->relationLoaded('properties') ? $room->properties : $room->properties()->get())
            ->map(fn (BoardProperty $p) => [
                'tile_index' => $p->tile_index,
                'owner_seat' => $p->owner_seat,
                'level' => $p->level,
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

        return [
            'room' => [
                'code' => $room->code,
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
                'win_reason' => $room->state['win_reason'] ?? null,
                'seconds_left' => $inMatch && $room->turn_deadline
                    ? max(0, $room->turn_deadline->getTimestamp() - now()->getTimestamp())
                    : null,
            ],
            'you' => $you,
            'players' => $players,
            'tiles' => $room->settings['tiles'] ?? [],
            'properties' => $properties,
            'ledger' => $ledger,
            'state' => $room->state,
        ];
    }

    /**
     * @return list<array{tile: int, name: string, cost: int, level: int}>
     */
    private function upgradeOptions(Room $room, Player $viewer): array
    {
        $tiles = $room->settings['tiles'] ?? [];
        $max = (int) ($room->settings['max_upgrade_level'] ?? 4);
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
                'level' => (int) $owned->level,
            ];
        }

        return $out;
    }
}
