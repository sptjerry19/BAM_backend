<?php

namespace App\Services\Auth;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: array}|null
     */
    public function login(array $credentials): ?array
    {
        if (! $token = auth()->attempt($credentials)) {
            return null;
        }

        /** @var User $user */
        $user = auth()->user();

        return $this->tokenPayload($token, $user);
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: array}
     */
    public function register(array $data): array
    {
        $memberId = 'm_'.Str::lower(Str::random(10));

        $member = Member::create([
            'id' => $memberId,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'avatar' => 'https://i.pravatar.cc/150?u='.urlencode($data['email']),
            'level' => 'beginner',
            'points' => 0,
            'matches' => 0,
            'wins' => 0,
            'total_cost' => 0,
            'join_date' => now()->toDateString(),
            'status' => 'active',
            'equipment' => [],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'user',
            'member_id' => $member->id,
        ]);

        $token = auth()->login($user);

        return $this->tokenPayload($token, $user);
    }

    public function me(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->toAuthArray();
    }

    public function logout(): void
    {
        auth()->logout();
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: array}
     */
    public function refresh(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $token = auth()->refresh();

        return $this->tokenPayload($token, $user);
    }

    /**
     * @return array{email: string, reset_token: string}|null
     */
    public function forgotPassword(string $email): ?array
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return null;
        }

        $plainToken = Str::random(48);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($plainToken),
                'created_at' => now(),
            ]
        );

        return [
            'email' => $user->email,
            'reset_token' => $plainToken,
        ];
    }

    /**
     * @return true|string Error translation key under auth.*
     */
    public function resetPassword(array $data): bool|string
    {
        $row = DB::table('password_reset_tokens')
            ->where('email', $data['email'])
            ->first();

        if (! $row || ! Hash::check($data['token'], $row->token)) {
            return 'reset_invalid';
        }

        if ($row->created_at && now()->diffInMinutes($row->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            return 'reset_expired';
        }

        $user = User::query()->where('email', $data['email'])->firstOrFail();
        $user->password = $data['password'];
        $user->save();

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return true;
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: array}
     */
    private function tokenPayload(string $token, User $user): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => $user->toAuthArray(),
        ];
    }
}
