<?php

namespace App\Services\ContentStudio\Pipeline;

use App\Enums\ContentStudio\ContentStatus;
use App\Enums\ContentStudio\RunStatus;
use App\Enums\ContentStudio\StepStatus;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Models\ContentStudio\ContentPipelineStep;
use App\Services\ContentStudio\Ops\AutomationGate;
use App\Services\ContentStudio\Ops\ContentEventLogger;
use App\Services\ContentStudio\State\ContentStateMachine;
use App\Services\ContentStudio\State\InvalidContentTransition;
use App\Services\ContentStudio\State\StaleContentItem;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one pipeline step with a lease so that it is safe to deliver the same
 * queue message twice, to kill the worker mid-step, or to restart the machine.
 *
 *  - succeeded step      -> returned as-is, the closure is NOT run again
 *  - running, live lease -> Busy (someone else is on it)
 *  - running, lease gone -> that attempt is closed as `lease_expired`, a new one starts
 *  - attempts used up    -> Exhausted: run (and item) are marked failed
 *
 * The database transaction is short and never wraps the work itself.
 */
final class StepRunner
{
    public function __construct(
        private readonly AutomationGate $gate,
        private readonly ContentEventLogger $events,
        private readonly ContentStateMachine $states,
        private readonly StepOutputProbe $probe,
    ) {}

    /**
     * @param  Closure(ContentPipelineStep): (array<string, mixed>|null)  $work  must be idempotent
     * @param  array<string, mixed>|null  $input  stored on the attempt for debugging
     *
     * @throws Throwable the work's own exception, after the attempt has been recorded
     */
    public function run(
        ContentPipelineRun $run,
        string $stepName,
        Closure $work,
        ?array $input = null,
        ?int $leaseSeconds = null,
        ?int $maxAttempts = null,
    ): StepOutcome {
        $leaseSeconds ??= (int) config('content-studio.steps.lease_seconds');
        $maxAttempts ??= (int) config('content-studio.steps.max_attempts');

        if ($this->gate->isPaused()) {
            $this->events->log('step.skipped_paused', 'pipeline_run', $run->id, ['step' => $stepName], 'system', $run->trace_id);

            return StepOutcome::paused();
        }

        [$step, $decision] = $this->claim($run, $stepName, $input, $leaseSeconds, $maxAttempts);

        if ($decision === 'done') {
            return StepOutcome::completed($step->output_json ?? []);
        }
        if ($decision === 'busy') {
            return StepOutcome::busy();
        }
        if ($decision === 'exhausted') {
            $this->failRun($run, "Step {$stepName} used all {$maxAttempts} attempts.");

            return StepOutcome::exhausted();
        }

        try {
            $output = $work($step) ?? [];
        } catch (Throwable $e) {
            $message = $this->events->scrub($e->getMessage());
            $step->forceFill([
                'status' => StepStatus::Failed,
                'finished_at' => now(),
                'lease_until' => null,
                'error_code' => class_basename($e),
                'error_message' => Str::limit($message, 2000, ''),
            ])->save();

            $permanent = $e instanceof NonRetryableStepException || $step->attempt >= $maxAttempts;
            $this->events->log(
                'step.failed', 'pipeline_run', $run->id,
                ['step' => $stepName, 'attempt' => $step->attempt, 'error' => class_basename($e), 'permanent' => $permanent],
                'system', $run->trace_id,
            );
            if ($permanent) {
                $this->failRun($run, "Step {$stepName}: ".Str::limit($message, 500, ''));
            }

            throw $e;
        }

        $step->forceFill([
            'status' => StepStatus::Succeeded,
            'finished_at' => now(),
            'lease_until' => null,
            'output_json' => $output,
        ])->save();
        $this->events->log('step.succeeded', 'pipeline_run', $run->id, ['step' => $stepName, 'attempt' => $step->attempt], 'system', $run->trace_id);

        return StepOutcome::completed($output);
    }

    /** @return array{0: ContentPipelineStep|null, 1: 'run'|'done'|'busy'|'exhausted'} */
    private function claim(ContentPipelineRun $run, string $stepName, ?array $input, int $leaseSeconds, int $maxAttempts): array
    {
        return DB::transaction(function () use ($run, $stepName, $input, $leaseSeconds, $maxAttempts) {
            // Serialise concurrent claimers on the run row.
            $locked = ContentPipelineRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            $last = ContentPipelineStep::query()
                ->where('run_id', $run->getKey())->where('step_name', $stepName)
                ->orderByDesc('attempt')->first();

            if ($last?->status === StepStatus::Succeeded) {
                return [$last, 'done'];
            }

            if ($last?->status === StepStatus::Running) {
                if ($last->lease_until?->isFuture()) {
                    return [$last, 'busy'];
                }
                // The worker is gone. If it had already written the declared output,
                // the work is done: do not render it again.
                if ($this->probe->close($last)) {
                    return [$last, 'done'];
                }
            }

            $attempt = ($last?->attempt ?? 0) + 1;
            if ($attempt > $maxAttempts) {
                return [$last, 'exhausted'];
            }

            if ($locked->status === RunStatus::Pending) {
                $locked->forceFill(['status' => RunStatus::Running, 'started_at' => now()])->save();
            }

            $step = ContentPipelineStep::create([
                'run_id' => $run->getKey(),
                'step_name' => $stepName,
                'status' => StepStatus::Running,
                'attempt' => $attempt,
                'started_at' => now(),
                'heartbeat_at' => now(),
                'lease_until' => now()->addSeconds($leaseSeconds),
                'input_json' => $input,
            ]);

            return [$step, 'run'];
        });
    }

    private function failRun(ContentPipelineRun $run, string $reason): void
    {
        $run->refresh();
        if ($run->status->isFinished()) {
            return;
        }
        $run->markFinished(RunStatus::Failed, $reason);
        $this->events->log('run.failed', 'pipeline_run', $run->id, ['reason' => $reason], 'system', $run->trace_id);

        $item = $run->item;
        if ($item === null || $item->status->isTerminal()) {
            return;
        }
        try {
            $this->states->transition($item, ContentStatus::Failed, $reason, 'system', $run->trace_id);
        } catch (InvalidContentTransition|StaleContentItem $e) {
            // e.g. the item is already `ready`/`scheduled` and is not allowed to jump to failed.
            $this->events->log('run.item_not_failed', 'content_item', $item->id, ['why' => $e->getMessage()], 'system', $run->trace_id);
        }
    }
}
