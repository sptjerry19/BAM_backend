<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\Seeder;

class ProductReviewSeeder extends Seeder
{
    public function run(): void
    {
        $reviews = [
            [
                'product_id' => 1,
                'member_id' => 1,
                'user_name' => 'Nguyễn Văn An',
                'avatar' => 'https://i.pravatar.cc/40?img=1',
                'rating' => 5,
                'comment' => 'Sản phẩm chất lượng cao, đánh rất êm tay. Giao hàng nhanh, đóng gói cẩn thận.',
            ],
            [
                'product_id' => 2,
                'member_id' => 2,
                'user_name' => 'Trần Thị Bích',
                'avatar' => 'https://i.pravatar.cc/40?img=5',
                'rating' => 4,
                'comment' => 'Tốt, giao hàng nhanh. Vợt hơi nặng so với mong đợi nhưng vẫn đáng tiền.',
            ],
            [
                'product_id' => 1,
                'member_id' => 3,
                'user_name' => 'Lê Minh Tuấn',
                'avatar' => 'https://i.pravatar.cc/40?img=3',
                'rating' => 5,
                'comment' => 'Dùng 3 tuần rồi, cảm giác đánh rất tốt. Smash mạnh và kiểm soát bóng chính xác.',
            ],
            [
                'product_id' => 3,
                'member_id' => 6,
                'user_name' => 'Vũ Thị Lan',
                'avatar' => 'https://i.pravatar.cc/40?img=6',
                'rating' => 4,
                'comment' => 'Màu đẹp, đi thoải mái. Power Cushion thật sự êm chân khi di chuyển nhiều.',
            ],
            [
                'product_id' => 2,
                'member_id' => 4,
                'user_name' => 'Phạm Thu Hà',
                'avatar' => 'https://i.pravatar.cc/40?img=9',
                'rating' => 3,
                'comment' => 'Ổn với giá tiền. Người mới chơi dùng được, chưa cần vợt quá cao cấp.',
            ],
        ];

        foreach ($reviews as $review) {
            ProductReview::firstOrCreate(
                [
                    'product_id' => $review['product_id'],
                    'member_id' => $review['member_id'],
                    'comment' => $review['comment'],
                ],
                $review
            );
        }

        foreach ([1, 2, 3] as $productId) {
            Product::find($productId)?->syncReviewStats();
        }
    }
}
