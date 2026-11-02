<?php

namespace App\Services\CapitalRivals;

final class DiceService
{
    /** @var (callable(int): int)|null */
    public static $generator = null;

    /**
     * @return list<int>
     */
    public static function roll(int $count = 2, int $sides = 6): array
    {
        $count = max(1, $count);
        $sides = max(2, $sides);
        $gen = self::$generator ?? static fn (int $max): int => random_int(1, $max);
        $dice = [];
        for ($i = 0; $i < $count; $i++) {
            $dice[] = (int) $gen($sides);
        }

        return $dice;
    }
}
