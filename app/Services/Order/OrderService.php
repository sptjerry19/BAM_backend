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

        $method = $data['payment_method'] ?? 'cod';
        // Real Apple Pay / MoMo gateways are not wired yet — keep pending until paid offline.
        $status = $data['status'] ?? 'pending';

        return Order::create([
            'member_id' => $data['member_id'] ?? null,
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'],
            'customer_email' => $data['customer_email'],
            'shipping_address' => $data['shipping_address'],
            'note' => $data['note'] ?? null,
            'payment_method' => $method,
            'total' => $total,
            'status' => $status,
            'items' => $items,
        ])->load('member');
    }
}
