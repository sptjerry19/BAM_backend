<?php

namespace App\Jobs\ContentStudio;

use App\Models\ContentStudio\ContentPipelineRun;
use App\Services\ContentStudio\Pipeline\NonRetryableStepException;
use App\Services\ContentStudio\Pipeline\StepOutcome;
use App\Services\ContentStudio\Pipeline\StepRunner;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Base class for every Content Studio pipeline job.
 *
 * Constructor arguments of subclasses must be scalars (e.g. a run id): the
 * StepDispatcher stores them on the run so recovery can rebuild the job.
 */
abstract class ContentStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** Seconds the queue worker lets one attempt run. Keep below `retry_after`. */
    public int $timeout = 180;

    public function __construct()
    {
        $this->route();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /** Light jobs go to the `content` queue; media jobs override this. */
    protected function route(): void
    {
        $this->onConnection(config('content-studio.queues.connection'))
            ->onQueue(config('content-studio.queues.default'));
    }

    /**
     * Run $work as a leased, idempotent step and translate the outcome into
     * queue behaviour.
     *
     * @param  Closure(\App\Models\ContentStudio\ContentPipelineStep): (array<string, mixed>|null)  $work
     */
    protected function execute(StepRunner $runner, ContentPipelineRun $run, string $stepName, Closure $work): StepOutcome
    {
        $lease = $this->timeout + (int) config('content-studio.steps.lease_grace_seconds');

        try {
            $outcome = $runner->run($run, $stepName, $work, leaseSeconds: $lease, maxAttempts: $this->tries);
        } catch (NonRetryableStepException $e) {
            $this->fail($e); // do not burn the remaining tries

            return StepOutcome::exhausted();
        }

        if ($outcome->state === StepOutcome::BUSY) {
            $this->release(60);
        } elseif ($outcome->state === StepOutcome::EXHAUSTED) {
            $this->fail(new RuntimeException("Step {$stepName} exhausted its attempts."));
        }
        // PAUSED: finish quietly. The run keeps its resume descriptor, so
        // content:recover continues it after automation is resumed.

        return $outcome;
    }
}
