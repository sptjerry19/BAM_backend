<?php

namespace App\Services\CapitalRivals;

final class ChanceDeck
{
    /** @var string|null */
    public static $nextId = null;

    /** @return array<string, array<string, mixed>> */
    public static function specCards(): array
    {
        return [
            'business_bonus' => ['title' => 'Thưởng kinh doanh', 'text' => 'Nhận $100.000.', 'kind' => 'credit', 'amount' => 100000, 'weight' => 12],
            'admin_fine' => ['title' => 'Phí hành chính', 'text' => 'Nộp $50.000.', 'kind' => 'debit', 'amount' => 50000, 'weight' => 8],
            'birthday' => ['title' => 'Sinh nhật', 'text' => 'Mỗi đối thủ tặng bạn $25.000.', 'kind' => 'birthday', 'amount' => 25000, 'weight' => 8],
            'go_start' => ['title' => 'Về Xuất phát', 'text' => 'Về Xuất phát và nhận $300.000.', 'kind' => 'go_start', 'weight' => 6],
            'go_island' => ['title' => 'Ra đảo', 'text' => 'Di chuyển đến Đảo hoang.', 'kind' => 'go_island', 'weight' => 10],
            'go_world_tour' => ['title' => 'Vòng quanh thế giới', 'text' => 'Di chuyển đến ô Vòng quanh thế giới.', 'kind' => 'go_world_tour', 'weight' => 8],
            'escape_card' => ['title' => 'Vé thoát đảo', 'text' => 'Giữ lại để thoát Đảo hoang.', 'kind' => 'saved', 'card' => 'island_escape', 'weight' => 10],
            'shield_card' => ['title' => 'Lá chắn', 'text' => 'Giữ lại để bảo vệ một thành phố.', 'kind' => 'saved', 'card' => 'shield', 'weight' => 8],
            'blackout' => ['title' => 'Mất điện', 'text' => 'Chọn một thành phố đối thủ: tiền thuê bằng 0 đến lượt sau của bạn.', 'kind' => 'target', 'effect' => 'blackout', 'weight' => 8],
            'earthquake' => ['title' => 'Động đất', 'text' => 'Chọn một thành phố đối thủ để phá toàn bộ nhà.', 'kind' => 'target', 'effect' => 'earthquake', 'weight' => 6],
            'free_championship' => ['title' => 'Đăng cai miễn phí', 'text' => 'Chọn một thành phố của bạn để đăng cai.', 'kind' => 'target', 'effect' => 'championship', 'weight' => 8],
            'go_tax' => ['title' => 'Kiểm tra thuế', 'text' => 'Di chuyển đến Sở thuế và nộp thuế.', 'kind' => 'go_tax', 'weight' => 8],
        ];
    }

    /** @return array<string, mixed> */
    public static function drawSpec(): array
    {
        if (is_string(self::$nextId) && isset(self::specCards()[self::$nextId])) {
            $id = self::$nextId;
            self::$nextId = null;
        } else {
            $roll = random_int(1, 100);
            $id = 'business_bonus';
            foreach (self::specCards() as $key => $card) {
                $roll -= (int) $card['weight'];
                if ($roll <= 0) {
                    $id = $key;
                    break;
                }
            }
        }
        $card = self::specCards()[$id];
        unset($card['weight']);

        return ['id' => $id, ...$card];
    }

    /**
     * Original Dispatch cards — not commercial-game text.
     *
     * @return array<string, array{title: string, text: string, kind: string, amount?: int, position?: int}>
     */
    public static function cards(bool $advanced = false): array
    {
        $cards = [
            'grant_50' => [
                'title' => 'Quà từ chuyến đi',
                'text' => 'Bạn nhận được $50 tiền thưởng du lịch.',
                'kind' => 'credit',
                'amount' => 50,
            ],
            'grant_100' => [
                'title' => 'Trúng thưởng may mắn',
                'text' => 'Bạn trúng thưởng $100. Tiền được cộng vào tài khoản.',
                'kind' => 'credit',
                'amount' => 100,
            ],
            'levy_50' => [
                'title' => 'Hành lý quá cân',
                'text' => 'Bạn cần trả $50 phí hành lý.',
                'kind' => 'debit',
                'amount' => 50,
            ],
            'levy_75' => [
                'title' => 'Sửa chữa phương tiện',
                'text' => 'Phương tiện gặp sự cố. Bạn cần trả $75 phí sửa chữa.',
                'kind' => 'debit',
                'amount' => 75,
            ],
            'recall_gate' => [
                'title' => 'Chuyến đi ra đảo',
                'text' => 'Di chuyển ngay đến ô Đảo, không nhận thưởng qua Xuất phát.',
                'kind' => 'move',
                'position' => 10,
            ],
            'harbor_gate' => [
                'title' => 'Trở về Xuất phát',
                'text' => 'Về ô Xuất phát và nhận tiền thưởng của một vòng.',
                'kind' => 'go_start',
            ],
        ];
        if ($advanced) {
            $cards['earthquake'] = ['title' => 'Động đất', 'text' => 'Nhà cấp 2–3 trên bàn giảm một cấp. Khách sạn không bị ảnh hưởng.', 'kind' => 'earthquake'];
            $cards['takeover'] = ['title' => 'Quyền tiếp quản', 'text' => 'Nhận ngẫu nhiên một nhà của đối thủ. Không áp dụng với khách sạn và bãi biển.', 'kind' => 'takeover'];
            $cards['festival'] = ['title' => 'Lễ hội thành phố', 'text' => 'Một thành phố ngẫu nhiên được tăng hệ số tiền thuê.', 'kind' => 'festival'];
        }

        return $cards;
    }

    /**
     * @return list<string>
     */
    public static function shuffled(bool $advanced = false): array
    {
        $ids = array_keys(self::cards($advanced));
        shuffle($ids);

        return array_values($ids);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{id: string, title: string, text: string, kind: string, amount?: int, position?: int}
     */
    public static function draw(array &$state, bool $advanced = false): array
    {
        if (is_string(self::$nextId) && self::$nextId !== '') {
            $id = self::$nextId;
            self::$nextId = null;
        } else {
            $pile = array_values(array_intersect($state['chance_draw'] ?? [], array_keys(self::cards($advanced))));
            if ($pile === []) {
                $pile = self::shuffled($advanced);
            }
            $id = (string) array_shift($pile);
            $state['chance_draw'] = $pile;
        }

        $card = self::cards($advanced)[$id] ?? self::cards($advanced)['grant_50'];

        return ['id' => $id, ...$card];
    }
}
