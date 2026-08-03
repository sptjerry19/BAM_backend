<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => 'nullable|string|exists:members,id',
            'status' => 'nullable|in:pending,paid,shipped',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|string|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'nullable|integer|min:0',
        ]);

        $items = [];
        $total = 0;

        foreach ($validated['items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
            $price = $item['price'] ?? $product->price;
            $qty = $item['qty'];
            $items[] = [
                'product_id' => $product->id,
                'qty' => $qty,
                'price' => $price,
            ];
            $total += $price * $qty;
        }

        $order = Order::create([
            'member_id' => $validated['member_id'] ?? null,
            'total' => $total,
            'status' => $validated['status'] ?? 'pending',
            'items' => $items,
        ]);

        return $this->success($order->load('member'), __('messages.created'), 201);
    }
}
