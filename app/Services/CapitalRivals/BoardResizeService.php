<?php

namespace App\Services\CapitalRivals;

use App\Models\CapitalRivals\LedgerEntry;
use App\Models\CapitalRivals\Room;
use App\Support\CapitalRivals\MatchStatus;

final class BoardResizeService
{
    // Remove two non-corner cells from each edge of the legacy 11 × 11 board.
    public const REMOVED = [7, 9, 12, 18, 23, 28, 34, 38];

    public static function indexMap(): array
    {
        $map = [];
        foreach (range(0, 39) as $index) {
            if (! in_array($index, self::REMOVED, true)) {
                $map[$index] = count($map);
            }
        }

        return $map;
    }

    public static function position(int $old, array $map): int
    {
        $old = max(0, min(39, $old));
        while (! array_key_exists($old, $map)) {
            $old--;
        }

        return $map[$old];
    }

    public static function refund(array $tile, int $level, array $settings): int
    {
        return (int) ($tile['price'] ?? 0) + max(0, $level) * PropertyUpgrade::cost($tile, $settings);
    }

    // Caller holds the room row lock inside a transaction.
    public function resize(Room $room): void
    {
        $settings = $room->settings ?? [];
        $oldTiles = $settings['tiles'] ?? [];
        if (count($oldTiles) !== 40) {
            return;
        }
        $map = self::indexMap();
        $byIndex = array_column($oldTiles, null, 'index');
        $tiles = [];
        $corners = array_column(BoardDefinition::tiles(), null, 'index');
        $beaches = ['Biển Mỹ Khê', 'Biển Nha Trang', 'Biển Sầm Sơn', 'Biển Mũi Né'];
        $beach = 0;
        foreach ($map as $old => $new) {
            $tile = [...$byIndex[$old], 'index' => $new, 'legacy_index' => $old];
            if ($new % 8 === 0) {
                $tile = $corners[$new];
            } elseif (($tile['type'] ?? '') === 'service') {
                $tile['special'] = 'beach';
                $tile['name'] = $beaches[$beach++];
            }
            $tiles[] = $tile;
        }

        // Delete removed holdings first, then shift retained indices in ascending
        // order to avoid the unique (room_id, tile_index) constraint colliding.
        foreach ($room->properties as $property) {
            $old = (int) $property->tile_index;
            if (array_key_exists($old, $map)) {
                continue;
            }
            $owner = $room->players->firstWhere('seat', $property->owner_seat);
            if ($owner) {
                $refund = self::refund($byIndex[$old] ?? [], (int) $property->level, $settings);
                $owner->cash = (int) $owner->cash + $refund;
                $owner->save();
                LedgerEntry::query()->create([
                    'room_id' => $room->id, 'type' => 'board_refund',
                    'to_player_id' => $owner->id, 'amount' => $refund,
                ]);
            }
            $property->delete();
        }
        foreach ($room->properties->sortBy('tile_index') as $property) {
            if (array_key_exists((int) $property->tile_index, $map)) {
                $property->tile_index = $map[(int) $property->tile_index];
                $property->save();
            }
        }
        foreach ($room->players as $player) {
            $player->position = self::position((int) $player->position, $map);
            $player->save();
        }
        $state = $room->state ?? [];
        $pending = $state['pending'] ?? null;
        if ($pending && isset($pending['tile'])) {
            $old = (int) $pending['tile'];
            if (array_key_exists($old, $map)) {
                $pending['tile'] = $map[$old];
                $pending['name'] = $tiles[$map[$old]]['name'];
                $state['pending'] = $pending;
            } else {
                $state['pending'] = null;
                if ($room->status === MatchStatus::IN_PROGRESS) {
                    $room->phase = MatchStatus::WAITING_FOR_ACTION;
                }
            }
        }
        if (isset($state['last_card']['position'])) {
            $state['last_card']['position'] = self::position((int) $state['last_card']['position'], $map);
        }
        $room->settings = [...$settings, 'tiles' => $tiles, 'board_side' => 9, 'board_version' => 3];
        $room->state = $state;
        if ($room->status === MatchStatus::IN_PROGRESS) {
            $room->turn_deadline = now()->addSeconds((int) ($settings['turn_timeout'] ?? 30));
        }
        $room->version++;
        $room->save();
        $room->load(['players', 'properties']);
    }
}
