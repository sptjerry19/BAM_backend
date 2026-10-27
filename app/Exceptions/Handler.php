<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($this->isApiRequest($request)) {
            return $this->renderApi($e);
        }

        return parent::render($request, $e);
    }

    private function isApiRequest(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private function renderApi(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return ApiResponse::error(
                $e->validator->errors()->first() ?: __('messages.validation_error'),
                $e->status,
                $e->errors()
            );
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::error(__('auth.unauthorized'), 401);
        }

        if ($e instanceof AuthorizationException) {
            return ApiResponse::error(__('auth.forbidden'), 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return ApiResponse::error(__('messages.not_found'), 404);
        }

        if ($e instanceof CapitalRivalsException) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus, ['code' => $e->errorCode]);
        }

        if ($e instanceof QueryException || $e instanceof PDOException) {
            report($e);

            return ApiResponse::error(__('messages.server_error'), 500);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return ApiResponse::error(
                $status === 404 ? __('messages.not_found') : __('messages.server_error'),
                $status
            );
        }

        report($e);

        return ApiResponse::error(__('messages.server_error'), 500);
    }
}
