<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductReviewController extends Controller
{
    public function index(string $product): JsonResponse
    {
        Product::findOrFail($product);

        $reviews = ProductReview::query()
            ->where('product_id', $product)
            ->orderByDesc('created_at')
            ->get();

        $distribution = ProductReview::query()
            ->where('product_id', $product)
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

        return $this->success([
            'reviews' => $reviews,
            'summary' => [
                'avg_rating' => $avgRating,
                'total' => $total,
                'distribution' => $distribution,
            ],
        ]);
    }

    public function store(Request $request, string $product): JsonResponse
    {
        Product::findOrFail($product);

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string',
            'member_id' => 'nullable|string|exists:members,id',
            'user_name' => 'nullable|string|max:255',
            'avatar' => 'nullable|string|max:2048',
        ]);

        $member = null;
        if (! empty($validated['member_id'])) {
            $member = Member::find($validated['member_id']);
        }

        $review = ProductReview::create([
            'product_id' => $product,
            'member_id' => $validated['member_id'] ?? null,
            'user_name' => $validated['user_name']
                ?? $member?->name
                ?? 'Anonymous',
            'avatar' => $validated['avatar'] ?? $member?->avatar,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'],
        ]);

        Product::findOrFail($product)->syncReviewStats();

        return $this->success($review, __('messages.created'), 201);
    }
}
