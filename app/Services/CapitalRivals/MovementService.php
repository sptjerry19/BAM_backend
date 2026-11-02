<?php

namespace App\Services\CapitalRivals;

final class MovementService
{
    /**
     * @return array{position: int, laps: int}
     */
    public static function advance(int $from, int $steps, int $size = 40): array
    {
        $size = max(1, $size);
        $from = (($from % $size) + $size) % $size;
        $steps = max(0, $steps);
        $raw = $from + $steps;

        return [
            'position' => $raw % $size,
            'laps' => intdiv($raw, $size),
        ];
    }
}
