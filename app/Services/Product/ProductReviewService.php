<?php

namespace App\Services\Product;

use App\Models\Member;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Support\Collection;

class ProductReviewService
{
    /**
     * @return array{reviews: Collection, summary: array{avg_rating: float|int, total: int, distribution: array<int, int>}}
     */
    public function listForProduct(string $productId): array
    {
        Product::findOrFail($productId);

        $reviews = ProductReview::query()
            ->where('product_id', $productId)
            ->orderByDesc('created_at')
            ->get();

        $distribution = ProductReview::query()
            ->where('product_id', $productId)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->all();

        $distribution = array_replace(
            array_fill_keys([5, 4, 3, 2, 1], 0),
            array_map('intval', $distribution)
        );

        $total = $reviews->count();
        $avgRating = $total > 0
            ? round($reviews->avg('rating'), 1)
            : 0;

        return [
            'reviews' => $reviews,
            'summary' => [
                'avg_rating' => $avgRating,
                'total' => $total,
                'distribution' => $distribution,
            ],
        ];
    }

    public function create(string $productId, array $data): ProductReview
    {
        Product::findOrFail($productId);

        $member = null;
        if (! empty($data['member_id'])) {
            $member = Member::find($data['member_id']);
        }

        $review = ProductReview::create([
            'product_id' => $productId,
            'member_id' => $data['member_id'] ?? null,
            'user_name' => $data['user_name']
                ?? $member?->name
                ?? 'Anonymous',
            'avatar' => $data['avatar'] ?? $member?->avatar,
            'rating' => $data['rating'],
            'comment' => $data['comment'],
        ]);

        Product::findOrFail($productId)->syncReviewStats();

        return $review;
    }
}
