<?php

namespace App\Services\CapitalRivals;

final class BoardDefinition
{
    /**
     * Original 40-tile circuit for Capital Rivals (not a copy of any commercial board).
     *
     * @return list<array<string, mixed>>
     */
    public static function tiles(): array
    {
        $p = static fn (int $i, string $name, string $group, int $price, int $rent): array => [
            'index' => $i,
            'type' => 'property',
            'name' => $name,
            'group' => $group,
            'price' => $price,
            'rent' => [$rent, (int) ($rent * 2.5), $rent * 5, $rent * 10, $rent * 15],
        ];

        $tiles = [
            ['index' => 0, 'type' => 'start', 'name' => 'Harbor Gate'],
            $p(1, 'Lantern Row', 'umber', 60, 4),
            ['index' => 2, 'type' => 'chance', 'name' => 'Dispatch'],
            $p(3, 'Quay Cottages', 'umber', 60, 4),
            ['index' => 4, 'type' => 'tax', 'name' => 'Circuit Levy', 'amount' => 200],
            ['index' => 5, 'type' => 'service', 'name' => 'North Shuttle', 'price' => 200, 'rent' => [25, 50, 100, 200]],
            $p(6, 'Maple Court', 'sky', 100, 6),
            ['index' => 7, 'type' => 'chance', 'name' => 'Dispatch'],
            $p(8, 'Willow Court', 'sky', 100, 6),
            $p(9, 'Cedar Court', 'sky', 120, 8),
            ['index' => 10, 'type' => 'detention', 'name' => 'Holding Yard'],
            $p(11, 'Rose Quarter', 'rose', 140, 10),
            ['index' => 12, 'type' => 'tax', 'name' => 'Permit Fee', 'amount' => 100],
            $p(13, 'Camellia Yard', 'rose', 140, 10),
            $p(14, 'Peony Yard', 'rose', 160, 12),
            ['index' => 15, 'type' => 'service', 'name' => 'East Shuttle', 'price' => 200, 'rent' => [25, 50, 100, 200]],
            $p(16, 'Amber Dock', 'amber', 180, 14),
            $p(17, 'Copper Dock', 'amber', 180, 14),
            ['index' => 18, 'type' => 'rest', 'name' => 'Canal Plaza'],
            $p(19, 'Bronze Dock', 'amber', 200, 16),
            ['index' => 20, 'type' => 'rest', 'name' => 'Midway Park'],
            $p(21, 'Crimson Hill', 'crimson', 220, 18),
            ['index' => 22, 'type' => 'chance', 'name' => 'Dispatch'],
            $p(23, 'Scarlet Hill', 'crimson', 220, 18),
            $p(24, 'Ruby Hill', 'crimson', 240, 20),
            ['index' => 25, 'type' => 'service', 'name' => 'South Shuttle', 'price' => 200, 'rent' => [25, 50, 100, 200]],
            $p(26, 'Sunfield', 'gold', 260, 22),
            $p(27, 'Wheatfield', 'gold', 260, 22),
            ['index' => 28, 'type' => 'rest', 'name' => 'Clubhouse'],
            $p(29, 'Harvest Field', 'gold', 280, 24),
            ['index' => 30, 'type' => 'go_to_detention', 'name' => 'Recall Gate'],
            $p(31, 'Pine Crest', 'pine', 300, 26),
            $p(32, 'Fir Crest', 'pine', 300, 26),
            $p(33, 'Cedar Crest', 'pine', 320, 28),
            ['index' => 34, 'type' => 'rest', 'name' => 'Skybridge'],
            ['index' => 35, 'type' => 'service', 'name' => 'West Shuttle', 'price' => 200, 'rent' => [25, 50, 100, 200]],
            ['index' => 36, 'type' => 'chance', 'name' => 'Dispatch'],
            $p(37, 'Summit Row', 'navy', 350, 35),
            ['index' => 38, 'type' => 'tax', 'name' => 'Luxury Levy', 'amount' => 100],
            $p(39, 'Capitol Row', 'navy', 400, 50),
        ];

        usort($tiles, static fn (array $a, array $b) => $a['index'] <=> $b['index']);

        return $tiles;
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        return [
            ...config('capital_rivals'),
            'tiles' => self::tiles(),
        ];
    }
}
