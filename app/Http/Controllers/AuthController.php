<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthRequest;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api', [
            'except' => ['login', 'register', 'forgotPassword', 'resetPassword'],
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! $token = auth()->attempt($credentials)) {
            return $this->error(__('auth.login_failed'), 401);
        }

        /** @var User $user */
        $user = auth()->user();

        return $this->success($this->tokenPayload($token, $user), __('auth.login_success'));
    }

    public function register(AuthRequest $request): JsonResponse
    {
        $memberId = 'm_'.Str::lower(Str::random(10));

        $member = Member::create([
            'id' => $memberId,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'avatar' => 'https://i.pravatar.cc/150?u='.urlencode($request->email),
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
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'role' => 'user',
            'member_id' => $member->id,
        ]);

        $token = auth()->login($user);

        return $this->success(
            $this->tokenPayload($token, $user),
            __('auth.register_success'),
            201
        );
    }

    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->success($user->toAuthArray());
    }

    public function logout(): JsonResponse
    {
        auth()->logout();

        return $this->success(null, __('auth.logout_success'));
    }

    public function refresh(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $token = auth()->refresh();

        return $this->success($this->tokenPayload($token, $user));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user) {
            return $this->error(__('auth.email_not_found'), 404);
        }

        $plainToken = Str::random(48);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($plainToken),
                'created_at' => now(),
            ]
        );

        // Demo: return token so FE can reset without a mail server.
        return $this->success([
            'email' => $user->email,
            'reset_token' => $plainToken,
        ], __('auth.forgot_success'));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $row = DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->first();

        if (! $row || ! Hash::check($validated['token'], $row->token)) {
            return $this->error(__('auth.reset_invalid'), 422);
        }

        if ($row->created_at && now()->diffInMinutes($row->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

            return $this->error(__('auth.reset_expired'), 422);
        }

        $user = User::query()->where('email', $validated['email'])->firstOrFail();
        $user->password = $validated['password'];
        $user->save();

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        return $this->success(null, __('auth.reset_success'));
    }

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
