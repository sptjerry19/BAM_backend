<?php

namespace App\Http\Controllers\Api\ContentStudio;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentSetting;
use App\Services\ContentStudio\Ops\AutomationGate;
use Illuminate\Http\JsonResponse;

/** Read-only in Phase 1; PATCH (with audit) comes with the first editable setting. */
class SettingsController extends Controller
{
    public function __invoke(AutomationGate $gate): JsonResponse
    {
        $channel = ContentChannel::default();

        return ApiResponse::success([
            'channel' => $channel->only(['platform', 'handle', 'timezone']),
            'flags' => [
                'enabled' => (bool) config('content-studio.enabled'),
                'autopublish_enabled' => (bool) config('content-studio.autopublish_enabled'),
                'schedule_scope' => config('content-studio.schedule_scope'),
            ],
            'automation' => [
                'paused' => $gate->isPaused(),
                'env_locked' => (bool) config('content-studio.automation_paused'),
                'details' => ContentSetting::where('channel_id', $channel->id)->where('key', AutomationGate::SETTING_KEY)->value('value_json'),
            ],
            'queues' => config('content-studio.queues'),
            'steps' => config('content-studio.steps'),
        ]);
    }
}
