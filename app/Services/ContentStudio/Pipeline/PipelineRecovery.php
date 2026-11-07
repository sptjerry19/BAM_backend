<?php

namespace App\Services\ContentStudio\Pipeline;

use App\Enums\ContentStudio\RunStatus;
use App\Enums\ContentStudio\StepStatus;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Models\ContentStudio\ContentPipelineStep;
use App\Services\ContentStudio\Ops\AutomationGate;
use App\Services\ContentStudio\Ops\ContentEventLogger;

/**
 * Brings the pipeline back to a consistent state after a lost queue message,
 * a killed worker, a pause, or the laptop being switched off.
 *
 * It never invents work: it only (1) closes attempts whose lease ran out and
 * (2) re-sends the job that is already recorded on an unfinished run. Because
 * StepRunner is idempotent, sending a job twice is harmless.
 */
final class PipelineRecovery
{
    public function __construct(
        private readonly AutomationGate $gate,
        private readonly StepDispatcher $dispatcher,
        private readonly ContentEventLogger $events,
        private readonly StepOutputProbe $probe,
    ) {}

    /**
     * @param  bool  $boot  true right after start-up: do not wait for the stale window
     * @return array{expired_steps: int, recovered_outputs: int, redispatched: int, rejected: int, paused: bool}
     */
    public function recover(bool $boot = false): array
    {
        $report = ['expired_steps' => 0, 'recovered_outputs' => 0, 'redispatched' => 0, 'rejected' => 0, 'paused' => false];

        // 1. Close attempts that were left "running" by a worker that is gone.
        ContentPipelineStep::query()
            ->where('status', StepStatus::Running->value)
            ->where('lease_until', '<', now())
            ->each(function (ContentPipelineStep $step) use (&$report) {
                $this->probe->close($step) ? $report['recovered_outputs']++ : $report['expired_steps']++;
            });

        // 2. While paused, do not start anything new.
        if ($this->gate->isPaused()) {
            $report['paused'] = true;
            $this->logIfNeeded($report);

            return $report;
        }

        // 3. Re-send the next job of every unfinished, idle run.
        $staleBefore = $boot ? now() : now()->subMinutes((int) config('content-studio.recover.stale_after_minutes', 10));

        ContentPipelineRun::query()
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->whereNotNull('resume_job_json')
            ->where('updated_at', '<=', $staleBefore)
            ->whereDoesntHave('steps', fn ($q) => $q
                ->where('status', StepStatus::Running->value)
                ->where('lease_until', '>', now()))
            ->each(function (ContentPipelineRun $run) use (&$report) {
                if ($this->dispatcher->redispatch($run)) {
                    $report['redispatched']++;
                } else {
                    $report['rejected']++;
                    $this->events->log('recover.rejected_descriptor', 'pipeline_run', $run->id, [], 'system', $run->trace_id);
                }
            });

        $this->logIfNeeded($report);

        return $report;
    }

    /** @param array{expired_steps: int, recovered_outputs: int, redispatched: int, rejected: int, paused: bool} $report */
    private function logIfNeeded(array $report): void
    {
        if ($report['expired_steps'] || $report['recovered_outputs'] || $report['redispatched'] || $report['rejected']) {
            $this->events->log('recover.completed', payload: $report);
        }
    }
}
