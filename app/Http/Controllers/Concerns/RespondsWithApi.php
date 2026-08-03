<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

trait RespondsWithApi
{
    protected function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200
    ): JsonResponse {
        return ApiResponse::success($data, $message, $status);
    }

    protected function error(
        ?string $message = null,
        int $status = 400,
        ?array $errors = null,
        mixed $data = null
    ): JsonResponse {
        return ApiResponse::error($message, $status, $errors, $data);
    }
}
