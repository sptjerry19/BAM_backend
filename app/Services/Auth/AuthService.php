<?php

namespace App\Services\Auth;

use App\Models\Member;
use App\Models\User;
use App\Services\Storage\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly FileUploadService $uploads)
    {
    }
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
        $user->loadMissing('member');

        if ($user->member && $user->member->status !== 'active') {
            auth()->logout();

            throw ValidationException::withMessages([
                'email' => [__('auth.account_hidden')],
            ]);
        }

        return $this->tokenPayload($token, $user);
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: array}
     */
    public function register(array $data): array
    {
        $member = Member::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'avatar' => 'https://i.pravatar.cc/150?u=' . urlencode($data['email']),
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

    public function updateProfile(array $data, ?UploadedFile $avatarFile = null): array
    {
        /** @var User $user */
        $user = auth()->user();

        if (! empty($data['password'])) {
            if (! Hash::check((string) ($data['current_password'] ?? ''), $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => [__('messages.current_password_invalid')],
                ]);
            }
            $user->password = $data['password'];
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->save();

        $member = $user->member;
        if ($member) {
            $avatar = $data['avatar'] ?? $member->avatar;
            if ($avatarFile) {
                $avatar = $this->uploads->upload($avatarFile, 'avatars')['url'];
            }

            $member->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'avatar' => $avatar,
            ]);
        }

        return $user->fresh()->toAuthArray();
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

        if ($row->created_at && now()->diffInMinutes($row->created_at, true) > 60) {
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
