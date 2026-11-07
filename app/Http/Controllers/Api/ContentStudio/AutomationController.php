<?php

namespace App\Http\Controllers\Api\ContentStudio;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\ContentStudio\Ops\AutomationGate;
use App\Services\ContentStudio\Pipeline\PipelineRecovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function pause(Request $request, AutomationGate $gate): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        $gate->pause($this->actor(), $data['reason'] ?? null);

        return ApiResponse::success(['paused' => true], 'Automation paused. Running steps finish; nothing new starts.');
    }

    public function resume(AutomationGate $gate, PipelineRecovery $recovery): JsonResponse
    {
        $gate->resume($this->actor());

        if ($gate->isPaused()) {
            // CONTENT_AUTOMATION_PAUSED=true in the environment overrides the admin switch.
            return ApiResponse::success(['paused' => true, 'env_locked' => true], 'Still paused by CONTENT_AUTOMATION_PAUSED in the environment.');
        }

        // Continue runs that were held while paused instead of waiting for the next tick.
        return ApiResponse::success(['paused' => false, 'recovery' => $recovery->recover(boot: true)], 'Automation resumed.');
    }

    private function actor(): string
    {
        return 'admin:'.auth('api')->id();
    }
}
