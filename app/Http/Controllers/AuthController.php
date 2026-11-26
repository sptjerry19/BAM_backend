<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\VerifyResetCodeRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $payload = $this->auth->login($request->validated());

        if (! $payload) {
            return $this->error(__('auth.login_failed'), 401);
        }

        return $this->success($payload, __('auth.login_success'));
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->success(
            $this->auth->register($request->validated()),
            __('auth.register_success'),
            201
        );
    }

    public function me(): JsonResponse
    {
        return $this->success($this->auth->me());
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $profile = $this->auth->updateProfile(
            $request->validated(),
            $request->file('avatar_file')
        );

        return $this->success($profile, __('messages.updated'));
    }

    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return $this->success(null, __('auth.logout_success'));
    }

    public function refresh(): JsonResponse
    {
        return $this->success($this->auth->refresh());
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->forgotPassword($request->validated()['email']);

        return $this->success(null, 'Nếu email đã đăng ký, chúng tôi đã gửi mã đặt lại mật khẩu.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $result = $this->auth->resetPassword($request->validated());

        if ($result !== true) {
            return $this->error(__('auth.'.$result), 422);
        }

        return $this->success(null, __('auth.reset_success'));
    }

    public function verifyResetCode(VerifyResetCodeRequest $request): JsonResponse
    {
        $grant = $this->auth->verifyResetCode($request->validated());

        if ($grant === null) {
            return $this->error(__('auth.reset_invalid'), 422);
        }

        return $this->success(['reset_grant' => $grant], 'Mã xác nhận hợp lệ.');
    }
}
