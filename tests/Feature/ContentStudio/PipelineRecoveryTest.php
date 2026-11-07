<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\RunStatus;
use App\Enums\ContentStudio\StepStatus;
use App\Jobs\ContentStudio\SmokeStep;
use App\Models\ContentStudio\ContentEvent;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Models\ContentStudio\ContentPipelineStep;
use App\Services\ContentStudio\Ops\AutomationGate;
use App\Services\ContentStudio\Pipeline\PipelineRecovery;
use App\Services\ContentStudio\Pipeline\StepDispatcher;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class PipelineRecoveryTest extends TestCase
{
    use UsesContentStudioDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['content-studio.queues.connection' => 'sync']);
    }

    /**
     * A run whose queue message was lost: the next job is recorded on the run
     * but nothing is in the queue. Nothing is dispatched here.
     */
    private function idleRun(int $minutesAgo = 30): ContentPipelineRun
    {
        $run = ContentPipelineRun::start(null, 'system.smoke');
        $run->forceFill([
            'resume_job_json' => ['class' => SmokeStep::class, 'args' => [$run->id]],
            'updated_at' => now()->subMinutes($minutesAgo),
        ])->saveQuietly();

        return $run;
    }

    public function test_lost_message_is_redispatched(): void
    {
        Queue::fake();
        $run = $this->idleRun();

        $report = app(PipelineRecovery::class)->recover();

        $this->assertSame(1, $report['redispatched']);
        Queue::assertPushed(SmokeStep::class, fn (SmokeStep $job) => $job->runId === $run->id);
    }

    public function test_recently_touched_runs_are_left_alone_unless_booting(): void
    {
        Queue::fake();
        $this->idleRun(minutesAgo: 1);

        $this->assertSame(0, app(PipelineRecovery::class)->recover()['redispatched']);
        Queue::assertNothingPushed();

        // After a restart the machine may have been off, so do not wait.
        $this->assertSame(1, app(PipelineRecovery::class)->recover(boot: true)['redispatched']);
    }

    public function test_second_recovery_pass_does_not_dispatch_again(): void
    {
        Queue::fake();
        $this->idleRun();
        $recovery = app(PipelineRecovery::class);

        $recovery->recover();
        $again = $recovery->recover();

        $this->assertSame(0, $again['redispatched'], 'redispatch touches the run, so the next tick waits');
        Queue::assertPushed(SmokeStep::class, 1);
    }

    public function test_killed_worker_lease_is_closed_and_the_job_redispatched(): void
    {
        Queue::fake();
        $run = $this->idleRun();
        $step = ContentPipelineStep::create([
            'run_id' => $run->id, 'step_name' => 'smoke', 'status' => StepStatus::Running, 'attempt' => 1,
            'started_at' => now()->subHour(), 'lease_until' => now()->subMinutes(40),
        ]);

        $report = app(PipelineRecovery::class)->recover();

        $this->assertSame(1, $report['expired_steps']);
        $this->assertSame(StepStatus::Failed, $step->fresh()->status);
        $this->assertSame('lease_expired', $step->fresh()->error_code);
        $this->assertSame(1, $report['redispatched']);
    }

    public function test_run_with_a_live_lease_is_not_redispatched(): void
    {
        Queue::fake();
        $run = $this->idleRun();
        ContentPipelineStep::create([
            'run_id' => $run->id, 'step_name' => 'smoke', 'status' => StepStatus::Running, 'attempt' => 1,
            'started_at' => now(), 'lease_until' => now()->addMinutes(10),
        ]);

        $this->assertSame(0, app(PipelineRecovery::class)->recover(boot: true)['redispatched']);
    }

    public function test_finished_runs_are_ignored(): void
    {
        Queue::fake();
        $run = $this->idleRun();
        $run->markFinished(RunStatus::Succeeded);

        $this->assertSame(0, app(PipelineRecovery::class)->recover(boot: true)['redispatched']);
    }

    public function test_paused_recovery_closes_leases_but_starts_nothing(): void
    {
        Queue::fake();
        $run = $this->idleRun();
        ContentPipelineStep::create([
            'run_id' => $run->id, 'step_name' => 'smoke', 'status' => StepStatus::Running, 'attempt' => 1,
            'started_at' => now()->subHour(), 'lease_until' => now()->subMinutes(40),
        ]);
        app(AutomationGate::class)->pause('tester');

        $report = app(PipelineRecovery::class)->recover(boot: true);

        $this->assertTrue($report['paused']);
        $this->assertSame(1, $report['expired_steps']);
        $this->assertSame(0, $report['redispatched']);
        Queue::assertNothingPushed();
    }

    public function test_untrusted_descriptor_from_the_database_is_rejected(): void
    {
        Queue::fake();
        $run = $this->idleRun();
        $run->forceFill(['resume_job_json' => ['class' => \stdClass::class, 'args' => []]])->saveQuietly();

        $report = app(PipelineRecovery::class)->recover(boot: true);

        $this->assertSame(1, $report['rejected']);
        $this->assertSame(0, $report['redispatched']);
        Queue::assertNothingPushed();
        $this->assertSame(1, ContentEvent::where('action', 'recover.rejected_descriptor')->count());
    }

    public function test_dispatcher_only_accepts_content_step_jobs_with_scalar_args(): void
    {
        Queue::fake();
        $run = ContentPipelineRun::start(null, 'x');
        $rejected = 0;

        foreach ([[\stdClass::class, []], [SmokeStep::class, [['not', 'scalar']]]] as [$class, $args]) {
            try {
                app(StepDispatcher::class)->dispatch($run, $class, $args);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }

        $this->assertSame(2, $rejected);
        $this->assertNull($run->fresh()->resume_job_json, 'a rejected job must not be remembered');
        Queue::assertNothingPushed();
    }

    /**
     * The whole point of Phase 1: kill the worker mid-step, restart the stack,
     * and the pipeline finishes without anyone touching it.
     */
    public function test_restart_scenario_finishes_the_run_end_to_end(): void
    {
        $run = $this->idleRun();
        ContentPipelineStep::create([
            'run_id' => $run->id, 'step_name' => 'smoke', 'status' => StepStatus::Running, 'attempt' => 1,
            'started_at' => now()->subHour(), 'lease_until' => now()->subMinutes(40), // worker died here
        ]);
        app(PipelineRecovery::class)->recover(boot: true);

        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $steps = $run->steps()->orderBy('attempt')->get();
        $this->assertSame([StepStatus::Failed, StepStatus::Succeeded], $steps->pluck('status')->all());
        $this->assertTrue($steps[1]->output_json['pong']);
    }
}
