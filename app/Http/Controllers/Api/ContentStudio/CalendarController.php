<?php

namespace App\Http\Controllers\Api\ContentStudio;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What is planned for which day, with the times that are only PROPOSALS. */
class CalendarController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $tz = (string) config('content-studio.timezone');
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'] ?? now($tz)->toDateString(), $tz)->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? $from->addDays(7)->toDateString(), $tz)->startOfDay();
        if ($from->diffInDays($to) > 62) {
            return ApiResponse::error('The range may not exceed 62 days.', 422);
        }

        $plans = ContentPlan::query()
            ->where('channel_id', ContentChannel::default()->id)
            ->whereDate('plan_date', '>=', $from->toDateString())
            ->whereDate('plan_date', '<=', $to->toDateString())
            ->with(['items' => fn ($q) => $q->orderBy('id')->withCount(['assets as slides' => fn ($a) => $a->where('kind', 'carousel_slide')])])
            ->orderBy('plan_date')
            ->get();

        $days = $plans->map(fn (ContentPlan $plan) => [
            'date' => $plan->plan_date->toDateString(),
            'status' => $plan->status,
            'shortfalls' => $plan->shortfalls_json ?? [],
            'items' => $plan->items->map(fn (ContentItem $i) => [
                'id' => $i->id,
                'slot' => $i->slot->value,
                'title' => $i->title,
                'status' => $i->status->value,
                'blocked_reason' => $i->blocked_reason,
                // a proposal only: `scheduled_at` stays null until a publisher really schedules it
                'proposed_publish_at' => $i->brief_json['proposed_publish_at'] ?? null,
                'scheduled_at' => $i->scheduled_at?->toIso8601String(),
                'slides_rendered' => (int) $i->slides,
                'mock' => (bool) ($i->brief_json['mock'] ?? false),
                'review_reason' => $i->brief_json['review_reason'] ?? null,
            ])->values(),
        ])->values();

        return ApiResponse::success(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $tz, 'days' => $days]);
    }
}
