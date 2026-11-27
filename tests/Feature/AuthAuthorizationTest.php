<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_login_and_admin_authorization(): void
    {
        $registered = $this->postJson('/api/register', [
            'name' => 'Player', 'email' => 'player@example.test', 'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
        $this->assertNotEmpty($registered->json('data.access_token'));
        $this->assertDatabaseHas('members', ['email' => 'player@example.test', 'status' => 'active']);
        $this->assertDatabaseHas('users', ['email' => 'player@example.test', 'role' => 'user']);

        $this->postJson('/api/login', ['email' => 'player@example.test', 'password' => 'wrong'])
            ->assertUnauthorized();
        $login = $this->postJson('/api/login', ['email' => 'player@example.test', 'password' => 'password123'])->assertOk();
        $this->withToken($login->json('data.access_token'))->getJson('/api/me')
            ->assertOk()->assertJsonPath('data.email', 'player@example.test');
        $this->getJson('/api/stats')->assertForbidden();

        $this->withToken(auth('api')->login(User::create([
            'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password123', 'role' => 'admin',
        ])))->getJson('/api/stats')->assertOk();
    }

    public function test_inactive_member_cannot_login(): void
    {
        $member = Member::create(['name' => 'Hidden', 'email' => 'hidden@example.test', 'join_date' => now()->toDateString(), 'status' => 'inactive']);
        User::create(['name' => 'Hidden', 'email' => $member->email, 'password' => 'password123', 'role' => 'user', 'member_id' => $member->id]);
        $this->postJson('/api/login', ['email' => $member->email, 'password' => 'password123'])->assertUnprocessable();
    }
}
