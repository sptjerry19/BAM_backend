<?php

namespace App\Services\CapitalRivals;

use App\Models\CapitalRivals\BoardProperty;
use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;

final class GameRules
{
    public static function enabled(array $settings): bool
    {
        return (int) ($settings['rules_version'] ?? 1) >= 2;
    }

    public static function isV3(array $settings): bool
    {
        return (int) ($settings['rules_version'] ?? 1) >= 3;
    }

    public static function stats(Room $room, int $seat): array
    {
        return [...['laps' => 0, 'gold' => 0, 'island_turns' => 0, 'island_failures' => 0, 'tour_waiting' => false, 'hand' => [], 'used_saved_card' => false, 'stake' => 0, 'winnings' => 0], ...($room->state['player_stats'][$seat] ?? [])];
    }

    public static function update(Room $room, int $seat, array $values): void
    {
        $state = $room->state ?? [];
        $state['player_stats'][$seat] = [...self::stats($room, $seat), ...$values];
        $room->state = $state;
    }

    public static function maxInitialLevel(array $settings, int $laps): int
    {
        return self::enabled($settings) && $laps < 1 ? 2 : 3;
    }

    public static function propertyValue(array $tile, int $level, array $settings): int
    {
        $base = (int) ($tile['price'] ?? 0);

        if (self::isV3($settings) && ($tile['type'] ?? '') === 'property') {
            return $base + min(3, max(0, $level)) * (int) ($tile['house_price'] ?? 0) + ($level >= 4 ? (int) ($tile['hotel_price'] ?? 0) : 0);
        }

        return $base + (($tile['type'] ?? '') === 'property' ? max(0, $level - 1) * PropertyUpgrade::cost($tile, $settings) : 0);
    }

    public static function netWorth(Room $room, Player $player): int
    {
        $tiles = array_column($room->settings['tiles'] ?? [], null, 'index');
        $worth = (int) $player->cash;
        foreach ($room->properties->where('owner_seat', $player->seat) as $property) {
            $worth += self::propertyValue($tiles[$property->tile_index] ?? [], (int) $property->level, $room->settings);
        }

        return $worth;
    }

    public static function rentAmount(Room $room, array $tile, BoardProperty $property): int
    {
        if (isset($room->state['blackouts'][$property->tile_index])) {
            return 0;
        }

        $festival = isset($room->state['festivals'][$property->tile_index]) ? 2 : 1;
        if (($tile['type'] ?? '') === 'service') {
            $tiles = array_column($room->settings['tiles'] ?? [], null, 'index');
            $count = $room->properties
                ->where('owner_seat', $property->owner_seat)
                ->filter(fn (BoardProperty $owned): bool => ($tiles[$owned->tile_index]['type'] ?? '') === 'service')
                ->count();

            return ([1 => 50000, 2 => 100000, 3 => 200000, 4 => 0][$count] ?? 0) * $festival;
        }

        $level = min(4, max(0, (int) $property->level));
        $levelMultiplier = [1, 3, 6, 10, 18][$level];
        $group = (string) ($tile['group'] ?? '');
        $groupIndexes = collect($room->settings['tiles'] ?? [])->where('group', $group)->pluck('index')->all();
        $ownedIndexes = $room->properties->where('owner_seat', $property->owner_seat)->pluck('tile_index')->all();
        $monopolyBonus = $groupIndexes !== [] && array_diff($groupIndexes, $ownedIndexes) === [] ? 1 : 0;
        $championship = (int) (($room->state['championship']['tile'] ?? -1) === $property->tile_index
            ? ($room->state['championship']['points'] ?? 0)
            : 0);
        $multiplier = min(
            (int) ($room->settings['max_rent_multiplier'] ?? 10),
            (1 + $monopolyBonus + $championship) * $festival,
        );

        return (int) ($tile['base_rent'] ?? 0) * $levelMultiplier * $multiplier;
    }

    public static function monopoly(Room $room, int $seat): ?string
    {
        $owned = array_map('intval', $room->properties->where('owner_seat', $seat)->pluck('tile_index')->all());
        $groups = [];
        $resorts = [];
        foreach ($room->settings['tiles'] ?? [] as $tile) {
            if (($tile['type'] ?? '') === 'property') {
                $groups[$tile['group'] ?? ''][] = (int) $tile['index'];
            }
            if (in_array(($tile['special'] ?? ''), ['beach', 'resort'], true)) {
                $resorts[] = (int) $tile['index'];
            }
        }
        if (count($resorts) === 4 && array_diff($resorts, $owned) === []) {
            return 'resort_monopoly';
        }
        if (self::isV3($room->settings)) {
            $complete = 0;
            foreach ($groups as $indexes) {
                $complete += array_diff($indexes, $owned) === [] ? 1 : 0;
            }
            $sides = [];
            foreach ($room->settings['tiles'] ?? [] as $tile) {
                if (($tile['type'] ?? '') === 'property') {
                    $sides[(int) $tile['side']][] = (int) $tile['index'];
                }
            }
            foreach ($sides as $indexes) {
                if (count($indexes) === 5 && array_diff($indexes, $owned) === []) {
                    return 'line_monopoly';
                }
            }

            return $complete >= 3 ? 'triple_monopoly' : null;
        }
        foreach ([3 => 'triple_monopoly', 2 => 'side_monopoly'] as $size => $reason) {
            foreach ($groups as $indexes) {
                if (count($indexes) === $size && array_diff($indexes, $owned) === []) {
                    return $reason;
                }
            }
        }

        return null;
    }

    public static function festivals(array $tiles, int $count = 2): array
    {
        $indexes = array_column(array_values(array_filter($tiles, fn ($tile) => in_array(($tile['type'] ?? ''), ['property', 'service'], true))), 'index');
        shuffle($indexes);
        $out = [];
        foreach (array_slice($indexes, 0, $count) as $index) {
            $out[$index] = 2;
        }

        return $out;
    }
}
