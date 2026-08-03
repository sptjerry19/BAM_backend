<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;

class ApiResponse
{
    /**
     * Success response.
     * Pass collection/model/array, or paginator (paginate / simplePaginate / cursorPaginate).
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200
    ): JsonResponse {
        [$payload, $pagination] = static::normalizeData($data);

        return response()->json([
            'success' => true,
            'message' => $message ?? __('messages.ok'),
            'data' => $payload,
            'meta' => $pagination ? ['pagination' => $pagination] : null,
            'errors' => null,
        ], $status, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Error response.
     *
     * @param  array<string, mixed>|null  $errors
     */
    public static function error(
        ?string $message = null,
        int $status = 400,
        ?array $errors = null,
        mixed $data = null
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message ?? __('messages.server_error'),
            'data' => $data,
            'meta' => null,
            'errors' => $errors,
        ], $status, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{0: mixed, 1: array<string, mixed>|null}
     */
    protected static function normalizeData(mixed $data): array
    {
        if ($data instanceof ResourceCollection) {
            $resource = $data->resource;

            if (static::isPaginator($resource)) {
                return [
                    $data->collection->values(),
                    static::paginationMeta($resource),
                ];
            }

            return [$data->resolve(), null];
        }

        if ($data instanceof JsonResource) {
            return [$data->resolve(), null];
        }

        if (static::isPaginator($data)) {
            return [
                collect($data->items())->values(),
                static::paginationMeta($data),
            ];
        }

        return [$data, null];
    }

    protected static function isPaginator(mixed $data): bool
    {
        return $data instanceof LengthAwarePaginator
            || $data instanceof Paginator
            || $data instanceof CursorPaginator
            || $data instanceof AbstractPaginator
            || $data instanceof AbstractCursorPaginator;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function paginationMeta(mixed $paginator): array
    {
        if ($paginator instanceof LengthAwarePaginator) {
            return [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
            ];
        }

        if ($paginator instanceof CursorPaginator || $paginator instanceof AbstractCursorPaginator) {
            return [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'has_more_pages' => $paginator->hasMorePages(),
            ];
        }

        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more_pages' => $paginator->hasMorePages(),
        ];
    }
}
