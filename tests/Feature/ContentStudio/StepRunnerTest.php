<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Enums\ContentStudio\RunStatus;
use App\Enums\ContentStudio\StepStatus;
use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentEvent;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Models\ContentStudio\ContentPipelineStep;
use App\Services\ContentStudio\Ops\AutomationGate;
use App\Services\ContentStudio\Pipeline\NonRetryableStepException;
use App\Services\ContentStudio\Pipeline\StepOutcome;
use App\Services\ContentStudio\Pipeline\StepRunner;
use App\Services\ContentStudio\State\ContentStateMachine;
use RuntimeException;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class StepRunnerTest extends TestCase
{
    use UsesContentStudioDatabase;

    private function runner(): StepRunner
    {
        return app(StepRunner::class);
    }

    private function newRun(?ContentItem $item = null): ContentPipelineRun
    {
        return ContentPipelineRun::start($item, 'test');
    }

    private function itemInProducing(): ContentItem
    {
        $item = ContentItem::create(['channel_id' => ContentChannel::default()->id, 'slot' => 'news', 'content_type' => 'carousel']);
        $machine = app(ContentStateMachine::class);
        foreach ([ContentStatus::Researching, ContentStatus::SourceVerified, ContentStatus::Scripted, ContentStatus::Producing] as $s) {
            $item = $machine->transition($item, $s);
        }

        return $item;
    }

    public function test_success_is_recorded_and_not_run_twice(): void
    {
        $run = $this->newRun();
        $calls = 0;
        $work = function () use (&$calls) {
            $calls++;

            return ['file' => 'a.png'];
        };

        $first = $this->runner()->run($run, 'render', $work);
        $second = $this->runner()->run($run, 'render', $work); // same queue message delivered again

        $this->assertTrue($first->isCompleted());
        $this->assertSame(['file' => 'a.png'], $second->output);
        $this->assertSame(1, $calls, 'a succeeded step must never be executed again');
        $this->assertSame(1, ContentPipelineStep::count());
        $this->assertSame(RunStatus::Running, $run->fresh()->status);
    }

    public function test_retryable_failure_then_success_uses_a_second_attempt(): void
    {
        $run = $this->newRun();

        try {
            $this->runner()->run($run, 'fetch', fn () => throw new RuntimeException('HTTP 503'));
            $this->fail('exception should propagate so the queue can retry');
        } catch (RuntimeException) {
        }

        $outcome = $this->runner()->run($run, 'fetch', fn () => ['ok' => true]);

        $this->assertTrue($outcome->isCompleted());
        $steps = ContentPipelineStep::orderBy('attempt')->get();
        $this->assertSame([StepStatus::Failed, StepStatus::Succeeded], $steps->pluck('status')->all());
        $this->assertSame([1, 2], $steps->pluck('attempt')->all());
        $this->assertSame('RuntimeException', $steps[0]->error_code);
        $this->assertSame(RunStatus::Running, $run->fresh()->status, 'one failure must not fail the run');
    }

    public function test_attempts_are_bounded_and_exhaustion_fails_run_and_item(): void
    {
        $item = $this->itemInProducing();
        $run = $this->newRun($item);
        $calls = 0;
        $boom = function () use (&$calls) {
            $calls++;
            throw new RuntimeException('still broken');
        };

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->runner()->run($run, 'render', $boom, maxAttempts: 3);
            } catch (RuntimeException) {
            }
        }
        $after = $this->runner()->run($run, 'render', $boom, maxAttempts: 3);

        $this->assertSame(StepOutcome::EXHAUSTED, $after->state);
        $this->assertSame(3, $calls, 'no 4th execution');
        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        $this->assertNull($run->fresh()->resume_job_json);
        $this->assertSame(ContentStatus::Failed, $item->fresh()->status);
        $this->assertStringContainsString('render', $item->fresh()->blocked_reason);
    }

    public function test_non_retryable_failure_fails_the_run_on_the_first_attempt(): void
    {
        $run = $this->newRun();

        $this->expectException(NonRetryableStepException::class);
        try {
            $this->runner()->run($run, 'rights', fn () => throw new NonRetryableStepException('rights_unverified'), maxAttempts: 3);
        } finally {
            $this->assertSame(RunStatus::Failed, $run->fresh()->status);
            $this->assertSame(1, ContentPipelineStep::count());
        }
    }

    public function test_live_lease_means_busy_and_work_is_not_run(): void
    {
        $run = $this->newRun();
        ContentPipelineStep::create([
            'run_id' => $run->id, 'step_name' => 'render', 'status' => StepStatus::Running, 'attempt' => 1,
            'started_at' => now(), 'lease_until' => now()->addMinutes(5),
        ]);
        $ran = false;

        $outcome = $this->runner()->run($run, 'render', function () use (&$ran) {
            $ran = true;
        });

        $this->assertSame(StepOutcome::BUSY, $outcome->state);
        $this->assertFalse($ran);
    }

    public function test_expired_lease_is_closed_and_a_new_attempt_takes_over(): void
    {
        $run = $this->newRun();
        ContentPipelineStep::create([
            'run_id' => $run->id, 'step_name' => 'render', 'status' => StepStatus::Running, 'attempt' => 1,
            'started_at' => now()->subHour(), 'lease_until' => now()->subMinutes(30), // worker was killed
        ]);

        $outcome = $this->runner()->run($run, 'render', fn () => ['done' => true]);

        $this->assertTrue($outcome->isCompleted());
        $old = ContentPipelineStep::where('attempt', 1)->sole();
        $this->assertSame(StepStatus::Failed, $old->status);
        $this->assertSame('lease_expired', $old->error_code);
        $this->assertSame(StepStatus::Succeeded, ContentPipelineStep::where('attempt', 2)->sole()->status);
    }

    public function test_paused_automation_does_not_start_the_step(): void
    {
        app(AutomationGate::class)->pause('tester', 'maintenance');
        $run = $this->newRun();
        $ran = false;

        $outcome = $this->runner()->run($run, 'render', function () use (&$ran) {
            $ran = true;
        });

        $this->assertSame(StepOutcome::PAUSED, $outcome->state);
        $this->assertFalse($ran);
        $this->assertSame(0, ContentPipelineStep::count());
        $this->assertSame(RunStatus::Pending, $run->fresh()->status);
    }

    public function test_env_kill_switch_also_pauses(): void
    {
        config(['content-studio.automation_paused' => true]);

        $this->assertTrue(app(AutomationGate::class)->isPaused());
    }

    public function test_heartbeat_extends_the_lease(): void
    {
        $run = $this->newRun();
        $captured = null;

        $this->runner()->run($run, 'render', function (ContentPipelineStep $step) use (&$captured) {
            $before = $step->lease_until;
            $this->travel(2)->minutes();
            $step->heartbeat(600);
            $captured = [$before, $step->fresh()->lease_until];

            return [];
        }, leaseSeconds: 60);

        $this->assertTrue($captured[1]->greaterThan($captured[0]->copy()->addMinutes(5)));
    }

    public function test_secrets_in_exception_messages_are_scrubbed_before_storage(): void
    {
        $run = $this->newRun();

        try {
            $this->runner()->run($run, 'ai', fn () => throw new RuntimeException('401 for Bearer sk-abcdef1234567890 key sk-zzzzzzzzzzzz'));
        } catch (RuntimeException) {
        }

        $message = ContentPipelineStep::sole()->error_message;
        $this->assertStringNotContainsString('abcdef1234567890', $message);
        $this->assertStringNotContainsString('sk-zzzzzzzzzzzz', $message);
        $this->assertStringContainsString('[redacted]', $message);
        $this->assertSame(0, ContentEvent::where('payload_redacted', 'like', '%sk-%')->count());
    }
}
