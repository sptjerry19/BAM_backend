<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $quantities = [];
            foreach ($data['items'] as $item) {
                $id = (string) $item['product_id'];
                $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['qty'];
            }

            $products = Product::query()->whereIn('id', array_keys($quantities))
                ->orderBy('id')->lockForUpdate()->get()->keyBy(fn (Product $product) => (string) $product->id);
            $items = [];
            $total = 0;
            foreach ($quantities as $id => $qty) {
                $product = $products->get($id);
                if (! $product || $product->stock < $qty) {
                    throw ValidationException::withMessages(['items' => ['Sản phẩm không còn đủ hàng.']]);
                }
                $items[] = ['product_id' => $product->id, 'qty' => $qty, 'price' => (int) $product->price];
                $total += (int) $product->price * $qty;
                $product->decrement('stock', $qty);
            }

            return Order::create([
                'member_id' => auth('api')->user()?->member_id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'],
                'shipping_address' => $data['shipping_address'],
                'note' => $data['note'] ?? null,
                'payment_method' => $data['payment_method'],
                'total' => $total,
                'status' => 'pending',
                'items' => $items,
            ])->load('member');
        });
    }
}
