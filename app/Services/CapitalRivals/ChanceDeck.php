<?php

namespace App\Services\CapitalRivals;

final class ChanceDeck
{
    /** @var string|null */
    public static $nextId = null;

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
