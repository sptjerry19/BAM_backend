<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_register_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/login', ['email' => 'missing@example.test', 'password' => 'wrong'])
                ->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'missing@example.test', 'password' => 'wrong'])
            ->assertStatus(429);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/register', [])->assertStatus(429);
    }

    public function test_checkout_and_admin_upload_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/orders', [])->assertUnprocessable();
        }
        $this->postJson('/api/orders', [])->assertStatus(429);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password123', 'role' => 'admin']);
        $this->withToken(auth('api')->login($admin));
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->postJson('/api/uploads', [])->assertUnprocessable();
        }
        $this->postJson('/api/uploads', [])->assertStatus(429);
    }

    public function test_booking_and_game_room_creation_are_rate_limited(): void
    {
        $user = User::create(['name' => 'Player', 'email' => 'player@example.test', 'password' => 'password123', 'role' => 'user']);
        $this->withToken(auth('api')->login($user));
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->postJson('/api/bookings', [])->assertUnprocessable();
        }
        $this->postJson('/api/bookings', [])->assertStatus(429);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/games/cr/rooms', [])->assertCreated();
        }
        $this->postJson('/api/games/cr/rooms', [])->assertStatus(429);
    }

    public function test_social_media_upload_is_rate_limited(): void
    {
        $user = User::create(['name' => 'Player', 'email' => 'player@example.test', 'password' => 'password123', 'role' => 'user']);
        $this->withToken(auth('api')->login($user));
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->postJson('/api/social-media', [])->assertUnprocessable();
        }
        $this->postJson('/api/social-media', [])->assertStatus(429);
    }
}
