<?php

namespace App\Http\Controllers\Api\ContentStudio;

use App\Enums\ContentStudio\ContentSlot;
use App\Enums\ContentStudio\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContentStudio\ContentEvent;
use App\Models\ContentStudio\ContentItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ContentStatus::class)],
            'slot' => ['nullable', Rule::enum(ContentSlot::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = ContentItem::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['slot'] ?? null, fn ($q, $v) => $q->where('slot', $v))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20, [
                'id', 'plan_id', 'slot', 'content_type', 'title', 'status', 'blocked_reason', 'scheduled_at', 'created_at', 'updated_at',
            ]);

        return ApiResponse::success($items);
    }

    public function show(ContentItem $item): JsonResponse
    {
        $item->load([
            'sources:id,platform,bucket,canonical_url,creator,rights_status',
            'assets:id,content_item_id,kind,storage_disk,path,mime,bytes,width,height,duration_ms,license_state',
            'runs' => fn ($q) => $q->latest()->limit(5)->with('steps:id,run_id,step_name,status,attempt,started_at,finished_at,error_code'),
        ]);

        $data = $item->toArray();
        $data['sources'] = $item->sources->map(fn ($s) => [
            'id' => $s->id, 'platform' => $s->platform, 'bucket' => $s->bucket, 'canonical_url' => $s->canonical_url,
            'creator' => $s->creator, 'usage' => $s->pivot->usage, 'rights_status' => $s->rights_status->value,
            'publishable' => $s->rights_status->allowsPublishing(),
        ])->all();
        $data['events'] = ContentEvent::query()
            ->where('entity_type', 'content_item')->where('entity_id', (string) $item->id)
            ->latest('id')->limit(50)->get(['id', 'actor', 'action', 'payload_redacted', 'created_at']);

        return ApiResponse::success($data);
    }
}
