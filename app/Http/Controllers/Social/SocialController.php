<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

abstract class SocialController extends Controller
{
    protected function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return parent::success($this->serializeDates($data), $message, $status);
    }

    private function serializeDates(mixed $value, string $key = ''): mixed
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $field => $item) {
                $value[$field] = $this->serializeDates($item, (string) $field);
            }

            return $value;
        }
        if (is_string($value) && str_ends_with($key, '_at') && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:/', $value)) {
            return Carbon::parse($value, config('app.timezone'))->toISOString();
        }

        return $value;
    }
}
