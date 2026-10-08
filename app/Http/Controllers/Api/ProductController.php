<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\BestsellersRequest;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Services\Product\ProductService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products)
    {
    }

    public function index(IndexProductRequest $request): JsonResponse
    {
        $params = $request->validated();
        $products = $this->products->list($params);
        return $this->success($products);
    }

    public function bestsellers(BestsellersRequest $request): JsonResponse
    {
        $limit = (int) ($request->validated()['limit'] ?? 5);

        return $this->success($this->products->bestsellers($limit));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated(), $request);

        return $this->success($product, __('messages.created'), 201);
    }

    public function show(string $id): JsonResponse
    {
        return $this->success($this->products->findOrFail($id));
    }

    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        $product = $this->products->findOrFail($id);
        $updated = $this->products->update($product, $request->validated(), $request);

        return $this->success($updated, __('messages.updated'));
    }

    public function destroy(string $id): JsonResponse
    {
        $product = $this->products->findOrFail($id);
        $this->products->delete($product);

        return $this->success(null, __('messages.deleted'));
    }
}
