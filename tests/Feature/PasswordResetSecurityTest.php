<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_token_is_emailed_and_never_returned_to_caller(): void
    {
        Mail::fake();
        User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'old-password', 'role' => 'user']);

        $known = $this->postJson('/api/forgot-password', ['email' => 'owner@example.test'])->assertOk();
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'other@example.test'])->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        $this->assertNull($known->json('data'));
        Mail::assertSent(PasswordResetCode::class, 1);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'owner@example.test']);

        $code = Mail::sent(PasswordResetCode::class)->first()->code;
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->postJson('/api/reset-password', [
            'email' => 'owner@example.test', 'token' => $code,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertUnprocessable();
        $wrongCode = $code === '000000' ? '000001' : '000000';
        $this->postJson('/api/verify-reset-code', ['email' => 'owner@example.test', 'code' => $wrongCode])
            ->assertUnprocessable();

        $verified = $this->postJson('/api/verify-reset-code', ['email' => 'owner@example.test', 'code' => $code])
            ->assertOk();
        $grant = $verified->json('data.reset_grant');
        $this->assertNotSame($code, $grant);
        $this->postJson('/api/verify-reset-code', ['email' => 'owner@example.test', 'code' => $code])
            ->assertUnprocessable();

        $this->postJson('/api/reset-password', [
            'email' => 'owner@example.test', 'token' => 'invalid',
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertUnprocessable();
        $this->assertTrue(Hash::check('old-password', User::where('email', 'owner@example.test')->first()->password));

        $this->postJson('/api/reset-password', [
            'email' => 'owner@example.test', 'token' => $grant,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertOk();
        $this->assertTrue(Hash::check('new-password', User::where('email', 'owner@example.test')->first()->password));
        $this->postJson('/api/reset-password', [
            'email' => 'owner@example.test', 'token' => $grant,
            'password' => 'replayed-password', 'password_confirmation' => 'replayed-password',
        ])->assertUnprocessable();
    }

    public function test_local_smtp_delivers_reset_email_to_mailpit(): void
    {
        if (getenv('MAILPIT_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set MAILPIT_INTEGRATION=1 with the local Mailpit container running.');
        }

        $this->assertSame('smtp', config('mail.default'));
        $mailpitHost = getenv('MAILPIT_HOST') ?: 'mailpit';
        $this->assertSame($mailpitHost, config('mail.mailers.smtp.host'));
        $this->assertSame(1025, (int) config('mail.mailers.smtp.port'));
        $email = 'reset-'.uniqid().'@example.test';
        User::create(['name' => 'Owner', 'email' => $email, 'password' => 'old-password', 'role' => 'user']);

        $this->postJson('/api/forgot-password', ['email' => $email])
            ->assertOk()->assertJsonPath('data', null);

        $messages = Http::get('http://'.$mailpitHost.':8025/api/v1/messages')->throw()->json('messages');
        $this->assertTrue(collect($messages)->contains(fn ($message) => collect($message['To'] ?? [])
            ->contains(fn ($recipient) => ($recipient['Address'] ?? null) === $email)));
    }
}
