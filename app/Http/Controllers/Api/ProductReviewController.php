<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductReview\StoreProductReviewRequest;
use App\Services\Product\ProductReviewService;
use Illuminate\Http\JsonResponse;

class ProductReviewController extends Controller
{
    public function __construct(private readonly ProductReviewService $reviews)
    {
    }

    public function index(string $product): JsonResponse
    {
        return $this->success($this->reviews->listForProduct($product));
    }

    public function store(StoreProductReviewRequest $request, string $product): JsonResponse
    {
        return $this->success(
            $this->reviews->create($product, $request->validated()),
            __('messages.created'),
            201
        );
    }
}
