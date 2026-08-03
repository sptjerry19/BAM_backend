<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('per_page')) {
            return $this->success($query->paginate((int) $request->integer('per_page', 15)));
        }

        return $this->success($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|string|unique:products,id',
            'name' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'category' => 'required|in:racket,shoes,shirt,shorts,shuttlecock,bag,grip,string',
            'price' => 'required|integer|min:0',
            'original_price' => 'nullable|integer|min:0',
            'image' => 'nullable|string|max:255',
            'stock' => 'required|integer|min:0',
            'rating' => 'nullable|numeric|min:0|max:5',
            'reviews' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'featured' => 'nullable|boolean',
        ]);

        return $this->success(Product::create($validated), __('messages.created'), 201);
    }

    public function show(string $id): JsonResponse
    {
        return $this->success(Product::findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'brand' => 'sometimes|string|max:255',
            'category' => 'sometimes|in:racket,shoes,shirt,shorts,shuttlecock,bag,grip,string',
            'price' => 'sometimes|integer|min:0',
            'original_price' => 'nullable|integer|min:0',
            'image' => 'nullable|string|max:255',
            'stock' => 'sometimes|integer|min:0',
            'rating' => 'nullable|numeric|min:0|max:5',
            'reviews' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'featured' => 'nullable|boolean',
        ]);

        $product->update($validated);

        return $this->success($product->fresh(), __('messages.updated'));
    }

    public function destroy(string $id): JsonResponse
    {
        Product::findOrFail($id)->delete();

        return $this->success(null, __('messages.deleted'));
    }
}
