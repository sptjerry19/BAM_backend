<?php

namespace App\Services\CapitalRivals;

final class BoardDefinition
{
    /**
     * Nine cells per edge including corners: 32 unique perimeter cells.
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
        $beach = static fn (int $i, string $name): array => [
            'index' => $i, 'type' => 'service', 'special' => 'beach',
            'name' => $name, 'price' => 200, 'rent' => [25, 50, 100, 200],
        ];
        $chance = static fn (int $i): array => [
            'index' => $i, 'type' => 'chance', 'name' => 'Vòng quay may mắn',
        ];

        return [
            ['index' => 0, 'type' => 'start', 'special' => 'start', 'name' => 'Xuất phát'],
            $p(1, 'Chợ Bến Thành', 'umber', 60, 4),
            $chance(2),
            $p(3, 'Bến Nhà Rồng', 'umber', 60, 4),
            ['index' => 4, 'type' => 'tax', 'name' => 'Thuế du lịch', 'amount' => 200],
            $beach(5, 'Biển Mỹ Khê'),
            $p(6, 'Phố cổ Hội An', 'sky', 100, 6),
            $p(7, 'Cầu Rồng Đà Nẵng', 'sky', 100, 6),
            ['index' => 8, 'type' => 'detention', 'special' => 'island', 'name' => 'Đảo Phú Quốc'],
            $p(9, 'Bà Nà Hills', 'sky', 120, 8),
            $p(10, 'Đại Nội Huế', 'rose', 140, 10),
            $p(11, 'Chùa Thiên Mụ', 'rose', 140, 10),
            $p(12, 'Động Phong Nha', 'rose', 160, 12),
            $beach(13, 'Biển Nha Trang'),
            $chance(14),
            $p(15, 'Hồ Hoàn Kiếm', 'amber', 180, 14),
            ['index' => 16, 'type' => 'rest', 'special' => 'world_tour', 'name' => 'World Tour'],
            $p(17, 'Văn Miếu', 'amber', 180, 14),
            $p(18, 'Phố cổ Hà Nội', 'amber', 200, 16),
            $p(19, 'Vịnh Hạ Long', 'crimson', 220, 18),
            $p(20, 'Đảo Cát Bà', 'crimson', 220, 18),
            $beach(21, 'Biển Sầm Sơn'),
            $p(22, 'Tràng An', 'crimson', 240, 20),
            $p(23, 'Sa Pa', 'gold', 260, 22),
            ['index' => 24, 'type' => 'rest', 'special' => 'airport', 'name' => 'Sân bay Tân Sơn Nhất'],
            $p(25, 'Đỉnh Fansipan', 'gold', 280, 24),
            $p(26, 'Thác Bản Giốc', 'pine', 300, 26),
            $chance(27),
            $p(28, 'Cột cờ Lũng Cú', 'pine', 320, 28),
            $beach(29, 'Biển Mũi Né'),
            $p(30, 'Dinh Độc Lập', 'navy', 350, 35),
            $p(31, 'Landmark 81', 'navy', 400, 50),
        ];
    }

    /** @return array<string, mixed> */
    public static function settings(bool $modern = true): array
    {
        $settings = config('capital_rivals');
        $tiles = self::tiles();
        if ($modern) {
            foreach ($tiles as &$tile) {
                if (isset($tile['price'])) {
                    $tile['price'] *= 1000;
                }
                if (isset($tile['amount'])) {
                    $tile['amount'] *= 1000;
                }
                if (isset($tile['rent'])) {
                    $tile['rent'] = array_map(fn ($rent) => $rent * 1000, $tile['rent']);
                }
            }
            unset($tile);
            $tiles[16]['special'] = 'championship';
            $tiles[16]['name'] = 'Giải vô địch thế giới';
            $settings = [...$settings, ...$settings['world_tour']];
        }

        return [
            ...$settings,
            'rules_version' => $modern ? 2 : 1,
            'board_version' => 3,
            'board_side' => 9,
            'tiles' => $tiles,
        ];
    }
}
