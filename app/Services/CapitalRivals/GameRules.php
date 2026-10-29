<?php

namespace App\Services\CapitalRivals;

use App\Models\CapitalRivals\Player;
use App\Models\CapitalRivals\Room;

final class GameRules
{
    public static function enabled(array $settings): bool
    {
        return (int) ($settings['rules_version'] ?? 1) >= 2;
    }

    public static function stats(Room $room, int $seat): array
    {
        return [...['laps' => 0, 'gold' => (int) ($room->settings['starting_gold'] ?? 15), 'island_turns' => 0, 'stake' => 0, 'winnings' => 0], ...($room->state['player_stats'][$seat] ?? [])];
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

    public static function monopoly(Room $room, int $seat): ?string
    {
        $owned = array_map('intval', $room->properties->where('owner_seat', $seat)->pluck('tile_index')->all());
        $groups = [];
        $resorts = [];
        foreach ($room->settings['tiles'] ?? [] as $tile) {
            if (($tile['type'] ?? '') === 'property') {
                $groups[$tile['group'] ?? ''][] = (int) $tile['index'];
            }
            if (($tile['special'] ?? '') === 'beach') {
                $resorts[] = (int) $tile['index'];
            }
        }
        if (count($resorts) === 4 && array_diff($resorts, $owned) === []) {
            return 'resort_monopoly';
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
        $indexes = array_column(array_values(array_filter($tiles, fn ($tile) => ($tile['type'] ?? '') === 'property')), 'index');
        shuffle($indexes);
        $out = [];
        foreach (array_slice($indexes, 0, $count) as $index) {
            $out[$index] = 2;
        }

        return $out;
    }
}
