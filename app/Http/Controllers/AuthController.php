<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
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
        $result = $this->auth->forgotPassword($request->validated()['email']);

        if (! $result) {
            return $this->error(__('auth.email_not_found'), 404);
        }

        return $this->success($result, __('auth.forgot_success'));
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $result = $this->auth->resetPassword($request->validated());

        if ($result !== true) {
            return $this->error(__('auth.'.$result), 422);
        }

        return $this->success(null, __('auth.reset_success'));
    }
}
