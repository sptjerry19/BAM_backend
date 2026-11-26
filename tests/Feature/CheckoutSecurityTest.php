<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $changes = []): array
    {
        return array_replace_recursive([
            'customer_name' => 'Test Buyer',
            'customer_phone' => '0900000000',
            'customer_email' => 'buyer@example.test',
            'shipping_address' => '123 Test Street',
            'payment_method' => 'cod',
            'items' => [['product_id' => '1', 'qty' => 2]],
        ], $changes);
    }

    public function test_client_cannot_set_price_status_or_member(): void
    {
        Product::create(['id' => 1, 'name' => 'Racket', 'brand' => 'Test', 'category' => 'racket', 'price' => 100, 'stock' => 3, 'rating' => 0, 'reviews' => 0]);

        $this->postJson('/api/orders', $this->payload([
            'status' => 'paid', 'member_id' => '999',
            'items' => [['product_id' => '1', 'qty' => 2, 'price' => 0]],
        ]))->assertCreated()->assertJsonPath('data.total', 200)->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.member_id', null)->assertJsonPath('data.items.0.price', 100);

        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 1]);
    }

    public function test_out_of_stock_order_is_rejected_without_writing(): void
    {
        Product::create(['id' => 1, 'name' => 'Racket', 'brand' => 'Test', 'category' => 'racket', 'price' => 100, 'stock' => 1, 'rating' => 0, 'reviews' => 0]);
        $this->postJson('/api/orders', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 1]);
    }
}
