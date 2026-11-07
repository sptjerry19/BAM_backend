<?php

namespace App\Http\Controllers\Api\ContentStudio;

use App\Enums\ContentStudio\RunStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContentStudio\ContentPipelineRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PipelineRunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RunStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::success(
            ContentPipelineRun::query()
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->latest('created_at')
                ->paginate($filters['per_page'] ?? 20, [
                    'id', 'content_item_id', 'pipeline', 'status', 'started_at', 'finished_at', 'last_error', 'created_at',
                ])
        );
    }

    public function show(ContentPipelineRun $run): JsonResponse
    {
        $run->load(['steps' => fn ($q) => $q->orderBy('id')]);

        // resume_job_json is an internal descriptor, not for the UI.
        return ApiResponse::success($run->makeHidden('resume_job_json')->toArray());
    }
}
