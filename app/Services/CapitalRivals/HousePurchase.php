<?php

namespace App\Services\CapitalRivals;

use App\Exceptions\CapitalRivalsException;

final class HousePurchase
{
    public static function price(array $tile, int $level, array $settings, int $laps = 0): int
    {
        if ($level < 1 || $level > 3 || (($tile['type'] ?? '') !== 'property' && $level !== 1)) {
            throw new CapitalRivalsException('invalid_state', __('messages.cr_initial_house_level'));
        }
        if (($tile['type'] ?? '') === 'property' && $level > GameRules::maxInitialLevel($settings, $laps)) {
            throw new CapitalRivalsException('invalid_state', __('messages.cr_lap_required'));
        }

        return (int) ($tile['price'] ?? 0) + ($level - 1) * PropertyUpgrade::cost($tile, $settings);
    }

    public static function options(array $tile, array $settings, int $laps = 0): array
    {
        $levels = ($tile['type'] ?? '') === 'property' ? range(1, GameRules::maxInitialLevel($settings, $laps)) : [1];

        return array_map(fn (int $level) => ['level' => $level, 'price' => self::price($tile, $level, $settings, $laps)], $levels);
    }
}
