<?php

namespace App\Jobs\ContentStudio;

use App\Enums\ContentStudio\RunStatus;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Services\ContentStudio\Pipeline\StepRunner;

/**
 * Does no AI, no network, no files. Exists to prove, on the real stack, that
 * scheduler -> Redis -> content-queue -> StepRunner -> MySQL works.
 * Run it with: php artisan content:smoke
 */
class SmokeStep extends ContentStepJob
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct();
    }

    public function handle(StepRunner $runner): void
    {
        $run = ContentPipelineRun::findOrFail($this->runId);

        $outcome = $this->execute($runner, $run, 'smoke', fn () => [
            'pong' => true,
            'host' => gethostname() ?: 'unknown',
            'at' => now()->toIso8601String(),
        ]);

        if ($outcome->isCompleted() && ! $run->fresh()->status->isFinished()) {
            $run->fresh()->markFinished(RunStatus::Succeeded);
        }
    }
}
