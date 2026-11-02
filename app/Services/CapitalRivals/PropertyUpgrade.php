<?php

namespace App\Services\CapitalRivals;

use App\Models\CapitalRivals\BoardProperty;
use Illuminate\Support\Collection;

final class PropertyUpgrade
{
    /**
     * @param  array<string, mixed>  $tile
     * @param  array<string, mixed>  $settings
     */
    public static function cost(array $tile, array $settings): int
    {
        $ratio = (float) ($settings['upgrade_cost_ratio'] ?? 0.5);
        $base = (int) ($tile['price'] ?? 0);

        return (int) max(1, round($base * $ratio));
    }

    /**
     * @param  Collection<int, BoardProperty>  $properties
     * @param  list<array<string, mixed>>  $tiles
     */
    public static function ownsGroup(int $seat, string $group, Collection $properties, array $tiles): bool
    {
        $indexes = [];
        foreach ($tiles as $tile) {
            if (($tile['type'] ?? '') === 'property' && ($tile['group'] ?? '') === $group) {
                $indexes[] = (int) $tile['index'];
            }
        }
        if ($indexes === []) {
            return false;
        }
        foreach ($indexes as $index) {
            $owned = $properties->firstWhere('tile_index', $index);
            if (! $owned || (int) $owned->owner_seat !== $seat) {
                return false;
            }
        }

        return true;
    }
}
