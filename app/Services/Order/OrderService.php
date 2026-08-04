<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\Product;

class OrderService
{
    public function create(array $data): Order
    {
        $items = [];
        $total = 0;

        foreach ($data['items'] as $item) {
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

        return Order::create([
            'member_id' => $data['member_id'] ?? null,
            'total' => $total,
            'status' => $data['status'] ?? 'pending',
            'items' => $items,
        ])->load('member');
    }
}
