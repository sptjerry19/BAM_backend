<?php

namespace App\Services\CapitalRivals;

final class BoardDefinition
{
    /** @return list<array<string, mixed>> */
    public static function tiles(): array
    {
        $groups = [
            'A' => [100000, 50000, 100000, 10000], 'B' => [150000, 60000, 120000, 15000],
            'C' => [200000, 80000, 160000, 20000], 'D' => [250000, 100000, 200000, 25000],
            'E' => [300000, 120000, 240000, 30000], 'F' => [350000, 140000, 280000, 35000],
            'G' => [400000, 160000, 320000, 40000], 'H' => [450000, 180000, 360000, 45000],
        ];
        $city = static function (int $index, string $name, string $group, int $side) use ($groups): array {
            [$land, $house, $hotel, $baseRent] = $groups[$group];

            return ['index' => $index, 'type' => 'property', 'name' => $name, 'group' => $group, 'side' => $side,
                'price' => $land, 'house_price' => $house, 'hotel_price' => $hotel, 'base_rent' => $baseRent,
                'rent' => array_map(static fn (int $m): int => $baseRent * $m, [1, 3, 6, 10, 18])];
        };
        $resort = static fn (int $index, string $name): array => ['index' => $index, 'type' => 'service', 'special' => 'resort', 'name' => $name, 'price' => 200000, 'rent' => [50000, 100000, 200000, 0]];
        $chance = static fn (int $index): array => ['index' => $index, 'type' => 'chance', 'name' => 'Cơ hội'];

        return [
            ['index' => 0, 'type' => 'start', 'special' => 'start', 'name' => 'Xuất phát'],
            $city(1, 'Chợ Bến Thành', 'A', 1), $city(2, 'Bến Nhà Rồng', 'A', 1), $resort(3, 'Biển Vũng Tàu'), $chance(4),
            $city(5, 'Phố cổ Hội An', 'B', 1), $city(6, 'Cầu Rồng', 'B', 1), $city(7, 'Bà Nà Hills', 'B', 1),
            ['index' => 8, 'type' => 'detention', 'special' => 'island', 'name' => 'Đảo hoang'],
            $city(9, 'Đại Nội Huế', 'C', 2), $city(10, 'Chùa Thiên Mụ', 'C', 2), $resort(11, 'Biển Mỹ Khê'), $chance(12),
            $city(13, 'Động Phong Nha', 'D', 2), $city(14, 'Hồ Hoàn Kiếm', 'D', 2), $city(15, 'Văn Miếu', 'D', 2),
            ['index' => 16, 'type' => 'championship', 'special' => 'championship', 'name' => 'Giải vô địch thế giới'],
            $city(17, 'Phố cổ Hà Nội', 'E', 3), $city(18, 'Vịnh Hạ Long', 'E', 3), $resort(19, 'Biển Nha Trang'), $chance(20),
            $city(21, 'Tràng An', 'F', 3), $city(22, 'Sa Pa', 'F', 3), $city(23, 'Đỉnh Fansipan', 'F', 3),
            ['index' => 24, 'type' => 'world_tour', 'special' => 'world_tour', 'name' => 'Vòng quanh thế giới'],
            $city(25, 'Thác Bản Giốc', 'G', 4), $city(26, 'Cột cờ Lũng Cú', 'G', 4), $resort(27, 'Biển Phú Quốc'),
            ['index' => 28, 'type' => 'tax', 'special' => 'tax', 'name' => 'Sở thuế', 'rate' => 0.10],
            $city(29, 'Dinh Độc Lập', 'H', 4), $city(30, 'Nhà thờ Đức Bà', 'H', 4), $city(31, 'Landmark 81', 'H', 4),
        ];
    }

    /** @return array<string, mixed> */
    public static function settings(bool $modern = true): array
    {
        if (! $modern) {
            return [...config('capital_rivals'), 'rules_version' => 1, 'board_version' => 3, 'board_side' => 9, 'tiles' => self::legacyTiles()];
        }

        return [...config('capital_rivals'), ...config('capital_rivals.business_tour_v1', []), 'rules_version' => 3, 'board_version' => 4, 'board_side' => 9, 'tiles' => self::tiles()];
    }

    /** @return list<array<string, mixed>> */
    private static function legacyTiles(): array
    {
        $p = static fn (int $i, string $name, string $group, int $price, int $rent): array => ['index' => $i, 'type' => 'property', 'name' => $name, 'group' => $group, 'price' => $price, 'rent' => [$rent, (int) ($rent * 2.5), $rent * 5, $rent * 10, $rent * 15]];
        $beach = static fn (int $i, string $name): array => ['index' => $i, 'type' => 'service', 'special' => 'beach', 'name' => $name, 'price' => 200, 'rent' => [25, 50, 100, 200]];
        $chance = static fn (int $i): array => ['index' => $i, 'type' => 'chance', 'name' => 'Vòng quay may mắn'];

        return [
            ['index' => 0, 'type' => 'start', 'special' => 'start', 'name' => 'Xuất phát'], $p(1, 'Chợ Bến Thành', 'umber', 60, 4), $chance(2), $p(3, 'Bến Nhà Rồng', 'umber', 60, 4),
            ['index' => 4, 'type' => 'tax', 'name' => 'Thuế du lịch', 'amount' => 200], $beach(5, 'Biển Mỹ Khê'), $p(6, 'Phố cổ Hội An', 'sky', 100, 6), $p(7, 'Cầu Rồng Đà Nẵng', 'sky', 100, 6),
            ['index' => 8, 'type' => 'detention', 'special' => 'island', 'name' => 'Đảo Phú Quốc'], $p(9, 'Bà Nà Hills', 'sky', 120, 8), $p(10, 'Đại Nội Huế', 'rose', 140, 10), $p(11, 'Chùa Thiên Mụ', 'rose', 140, 10), $p(12, 'Động Phong Nha', 'rose', 160, 12),
            $beach(13, 'Biển Nha Trang'), $chance(14), $p(15, 'Hồ Hoàn Kiếm', 'amber', 180, 14),
            ['index' => 16, 'type' => 'rest', 'special' => 'world_tour', 'name' => 'World Tour'], $p(17, 'Văn Miếu', 'amber', 180, 14), $p(18, 'Phố cổ Hà Nội', 'amber', 200, 16), $p(19, 'Vịnh Hạ Long', 'crimson', 220, 18), $p(20, 'Đảo Cát Bà', 'crimson', 220, 18),
            $beach(21, 'Biển Sầm Sơn'), $p(22, 'Tràng An', 'crimson', 240, 20), $p(23, 'Sa Pa', 'gold', 260, 22),
            ['index' => 24, 'type' => 'rest', 'special' => 'airport', 'name' => 'Sân bay Tân Sơn Nhất'], $p(25, 'Đỉnh Fansipan', 'gold', 280, 24), $p(26, 'Thác Bản Giốc', 'pine', 300, 26), $chance(27), $p(28, 'Cột cờ Lũng Cú', 'pine', 320, 28),
            $beach(29, 'Biển Mũi Né'), $p(30, 'Dinh Độc Lập', 'navy', 350, 35), $p(31, 'Landmark 81', 'navy', 400, 50),
        ];
    }
}
