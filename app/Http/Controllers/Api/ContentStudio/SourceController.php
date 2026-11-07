<?php

namespace App\Http\Controllers\Api\ContentStudio;

use App\Enums\ContentStudio\RightsStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContentStudio\ContentSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'rights_status' => ['nullable', Rule::enum(RightsStatus::class)],
            'bucket' => ['nullable', Rule::in(['news', 'reup', 'spec'])],
            'platform' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sources = ContentSource::query()
            ->when($filters['rights_status'] ?? null, fn ($q, $v) => $q->where('rights_status', $v))
            ->when($filters['bucket'] ?? null, fn ($q, $v) => $q->where('bucket', $v))
            ->when($filters['platform'] ?? null, fn ($q, $v) => $q->where('platform', $v))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->through(fn (ContentSource $s) => $s->toArray() + ['publishable' => $s->rights_status->allowsPublishing()]);

        return ApiResponse::success($sources);
    }
}
