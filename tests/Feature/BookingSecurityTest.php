<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $slots = []): array
    {
        return [
            'court_id' => Court::firstOrFail()->id,
            'booking_date' => now('Asia/Ho_Chi_Minh')->addWeek()->toDateString(),
            'slots' => $slots ?: [['court_number' => 1, 'start_time' => '10:00', 'hours' => 2]],
            'customer_name' => 'Player',
            'customer_phone' => '0900000000',
            'customer_email' => 'player@example.test',
            'payment_method' => 'venue',
        ];
    }

    private function signIn(): User
    {
        $member = Member::create(['name' => 'Owner', 'email' => 'owner@example.test', 'join_date' => now()->toDateString(), 'status' => 'active']);
        $user = User::create(['name' => 'Owner', 'email' => $member->email, 'password' => 'test-password', 'role' => 'user', 'member_id' => $member->id]);
        $this->withToken(auth('api')->login($user));

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Court::create(['name' => 'Test Court', 'address' => 'Test', 'district' => 'Test', 'surfaces' => 'wood', 'price' => 100, 'courts' => 2, 'status' => 'open', 'hours' => '06:00 - 22:00']);
    }

    public function test_booking_uses_authenticated_member_and_rejects_client_member_id(): void
    {
        $owner = $this->signIn();
        $other = Member::create(['name' => 'Other', 'email' => 'other@example.test', 'join_date' => now()->toDateString(), 'status' => 'active']);

        $this->postJson('/api/bookings', $this->payload() + ['member_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('member_id');
        $this->assertDatabaseCount('bookings', 0);

        $this->postJson('/api/bookings', $this->payload())->assertCreated();
        $this->assertDatabaseHas('bookings', ['member_id' => $owner->member_id, 'court_number' => 1]);
    }

    public function test_overlapping_requests_cannot_book_same_court_number_and_time(): void
    {
        $this->signIn();
        $this->postJson('/api/bookings', $this->payload())->assertCreated();
        $this->postJson('/api/bookings', $this->payload([['court_number' => 1, 'start_time' => '11:00', 'hours' => 2]]))
            ->assertUnprocessable()->assertJsonValidationErrors('slots.0.start_time');
        $this->postJson('/api/bookings', $this->payload([['court_number' => 2, 'start_time' => '10:00', 'hours' => 2]]))
            ->assertCreated();
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_overlapping_slots_in_one_request_roll_back_entire_booking(): void
    {
        $this->signIn();
        $this->postJson('/api/bookings', $this->payload([
            ['court_number' => 1, 'start_time' => '10:00', 'hours' => 2],
            ['court_number' => 1, 'start_time' => '11:00', 'hours' => 2],
        ]))->assertUnprocessable()->assertJsonValidationErrors('slots.1.start_time');
        $this->assertDatabaseCount('bookings', 0);
    }
}
