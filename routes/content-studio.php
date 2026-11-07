<?php

use App\Http\Controllers\Api\ContentStudio\AutomationController;
use App\Http\Controllers\Api\ContentStudio\ContentItemController;
use App\Http\Controllers\Api\ContentStudio\OverviewController;
use App\Http\Controllers\Api\ContentStudio\PipelineRunController;
use App\Http\Controllers\Api\ContentStudio\SettingsController;
use App\Http\Controllers\Api\ContentStudio\SourceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| JBAM AI Content Studio — admin API (read-only, plus pause/resume)
|--------------------------------------------------------------------------
| Loaded from routes/api.php, so the `api` prefix and middleware group apply.
| Admin only. The endpoints stay reachable while CONTENT_STUDIO_ENABLED=false
| so the dashboard can show that the module is switched off.
*/
Route::middleware(['auth:api', 'admin'])->prefix('admin/content-studio')->group(function () {
    Route::get('overview', OverviewController::class);

    Route::get('contents', [ContentItemController::class, 'index']);
    Route::get('contents/{item}', [ContentItemController::class, 'show'])->whereNumber('item');

    Route::get('sources', [SourceController::class, 'index']);

    Route::get('pipeline-runs', [PipelineRunController::class, 'index']);
    Route::get('pipeline-runs/{run}', [PipelineRunController::class, 'show'])->whereUuid('run');

    Route::get('settings', SettingsController::class);

    Route::post('automation/pause', [AutomationController::class, 'pause']);
    Route::post('automation/resume', [AutomationController::class, 'resume']);
});
