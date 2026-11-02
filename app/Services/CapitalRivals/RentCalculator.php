<?php

namespace App\Services\CapitalRivals;

use App\Models\CapitalRivals\BoardProperty;
use Illuminate\Support\Collection;

final class RentCalculator
{
    /**
     * @param  array<string, mixed>  $tile
     * @param  Collection<int, BoardProperty>  $properties
     * @param  list<array<string, mixed>>  $tiles
     */
    public static function amount(array $tile, int $ownerSeat, Collection $properties, array $tiles): int
    {
        $rents = array_values($tile['rent'] ?? [0]);
        if ($rents === []) {
            return 0;
        }

        if (($tile['type'] ?? '') === 'service') {
            $serviceIndexes = [];
            foreach ($tiles as $def) {
                if (($def['type'] ?? '') === 'service') {
                    $serviceIndexes[] = (int) $def['index'];
                }
            }
            $owned = $properties
                ->filter(fn (BoardProperty $p) => (int) $p->owner_seat === $ownerSeat && in_array((int) $p->tile_index, $serviceIndexes, true))
                ->count();
            $idx = max(0, min(count($rents) - 1, $owned - 1));

            return (int) $rents[$idx];
        }

        $owned = $properties->firstWhere('tile_index', (int) $tile['index']);
        $level = (int) ($owned?->level ?? 0);
        // Houses are now levels 1–4; legacy level-zero holdings use level 1 rent.
        $idx = max(0, min(count($rents) - 1, max(1, $level) - 1));

        return (int) $rents[$idx];
    }
}
