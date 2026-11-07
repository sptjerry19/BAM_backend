<?php

namespace App\Http\Controllers\Api\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Enums\ContentStudio\StepStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Models\ContentStudio\ContentPipelineStep;
use App\Services\ContentStudio\Ops\AutomationGate;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __invoke(AutomationGate $gate): JsonResponse
    {
        $channel = ContentChannel::default();
        $todayStart = now(config('content-studio.timezone'))->startOfDay();

        $blockers = ContentItem::query()
            ->where('channel_id', $channel->id)
            ->whereIn('status', [ContentStatus::Blocked->value, ContentStatus::Failed->value, ContentStatus::NeedsReview->value])
            ->latest('updated_at')->limit(10)
            ->get(['id', 'slot', 'title', 'status', 'blocked_reason', 'updated_at']);

        $freeBytes = @disk_free_space(storage_path());
        $totalBytes = @disk_total_space(storage_path());

        return ApiResponse::success([
            'flags' => [
                'enabled' => (bool) config('content-studio.enabled'),
                'autopublish_enabled' => (bool) config('content-studio.autopublish_enabled'),
                'paused' => $gate->isPaused(),
                'schedule_scope' => config('content-studio.schedule_scope'),
            ],
            'channel' => ['platform' => $channel->platform, 'handle' => $channel->handle],
            'items_by_status' => ContentItem::query()->where('channel_id', $channel->id)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'items_created_today' => ContentItem::query()->where('channel_id', $channel->id)
                ->where('created_at', '>=', $todayStart->copy()->utc())->count(),
            'pipeline' => [
                'running_steps' => ContentPipelineStep::where('status', StepStatus::Running->value)->count(),
                'failed_steps_24h' => ContentPipelineStep::where('status', StepStatus::Failed->value)
                    ->where('finished_at', '>=', now()->subDay())->count(),
                'runs_by_status' => ContentPipelineRun::query()->selectRaw('status, count(*) as total')
                    ->groupBy('status')->pluck('total', 'status'),
            ],
            'blockers' => $blockers,
            'system' => [
                'last_step_heartbeat_at' => ContentPipelineStep::max('heartbeat_at'),
                'disk_free_percent' => $freeBytes !== false && $totalBytes ? round($freeBytes / $totalBytes * 100, 1) : null,
            ],
        ]);
    }
}
