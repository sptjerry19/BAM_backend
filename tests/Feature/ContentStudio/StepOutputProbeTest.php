<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\StepStatus;
use App\Jobs\ContentStudio\SmokeStep;
use App\Models\ContentStudio\ContentEvent;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Models\ContentStudio\ContentPipelineStep;
use App\Services\ContentStudio\Pipeline\PipelineRecovery;
use App\Services\ContentStudio\Pipeline\StepOutcome;
use App\Services\ContentStudio\Pipeline\StepRunner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class StepOutputProbeTest extends TestCase
{
    use UsesContentStudioDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['content-studio.queues.connection' => 'sync']);
    }

    /** A step whose worker died: lease is in the past and it declared an output file. */
    private function deadStep(?array $expected, ?ContentPipelineRun $run = null): ContentPipelineStep
    {
        $run ??= ContentPipelineRun::start(null, 'test');

        return ContentPipelineStep::create([
            'run_id' => $run->id, 'step_name' => 'render', 'status' => StepStatus::Running, 'attempt' => 1,
            'started_at' => now()->subHour(), 'lease_until' => now()->subMinutes(30),
            'input_json' => $expected === null ? null : ['expected_output' => $expected],
        ]);
    }

    public function test_finished_output_is_recovered_instead_of_rendering_again(): void
    {
        Storage::disk('local')->put('content/1/slide-01.png', 'png-bytes');
        $step = $this->deadStep(['disk' => 'local', 'path' => 'content/1/slide-01.png']);
        $ran = false;

        $outcome = app(StepRunner::class)->run($step->run, 'render', function () use (&$ran) {
            $ran = true;
        });

        $this->assertSame(StepOutcome::COMPLETED, $outcome->state);
        $this->assertFalse($ran, 'the file is already there, no second render');
        $this->assertTrue($outcome->output['recovered']);
        $this->assertSame('content/1/slide-01.png', $outcome->output['path']);
        $this->assertSame(StepStatus::Succeeded, $step->fresh()->status);
        $this->assertSame(1, ContentPipelineStep::count(), 'no new attempt was created');
        $this->assertSame(1, ContentEvent::where('action', 'step.recovered_output')->count());
    }

    public function test_missing_output_means_the_step_is_run_again(): void
    {
        $step = $this->deadStep(['disk' => 'local', 'path' => 'content/1/never-written.png']);

        $outcome = app(StepRunner::class)->run($step->run, 'render', fn () => ['file' => 'content/1/never-written.png']);

        $this->assertTrue($outcome->isCompleted());
        $this->assertArrayNotHasKey('recovered', $outcome->output);
        $this->assertSame('lease_expired', $step->fresh()->error_code);
        $this->assertSame(2, ContentPipelineStep::max('attempt'));
    }

    public function test_a_step_without_a_declared_output_is_never_recovered(): void
    {
        Storage::disk('local')->put('content/1/slide-01.png', 'x');
        $step = $this->deadStep(null);

        $this->assertSame(['expired_steps' => 1, 'recovered_outputs' => 0], array_intersect_key(
            app(PipelineRecovery::class)->recover(), ['expired_steps' => 1, 'recovered_outputs' => 1],
        ));
        $this->assertSame(StepStatus::Failed, $step->fresh()->status);
    }

    public function test_recovery_service_closes_expired_steps_with_the_same_rule(): void
    {
        Queue::fake();
        Storage::disk('local')->put('ok.png', 'a');
        $good = $this->deadStep(['disk' => 'local', 'path' => 'ok.png']);
        $bad = $this->deadStep(['disk' => 'local', 'path' => 'missing.png']);
        $bad->forceFill(['step_name' => 'other'])->save(); // keep (run_id, step_name, attempt) unique

        $report = app(PipelineRecovery::class)->recover();

        $this->assertSame(1, $report['recovered_outputs']);
        $this->assertSame(1, $report['expired_steps']);
        $this->assertSame(StepStatus::Succeeded, $good->fresh()->status);
        $this->assertTrue($good->fresh()->output_json['recovered']);
        $this->assertSame(StepStatus::Failed, $bad->fresh()->status);
    }

    public function test_recovered_step_lets_the_run_continue_without_redoing_work(): void
    {
        $run = ContentPipelineRun::start(null, 'system.smoke');
        $run->forceFill([
            'resume_job_json' => ['class' => SmokeStep::class, 'args' => [$run->id]],
            'updated_at' => now()->subHour(),
        ])->saveQuietly();
        Storage::disk('local')->put('smoke.out', 'done');
        ContentPipelineStep::create([
            'run_id' => $run->id, 'step_name' => 'smoke', 'status' => StepStatus::Running, 'attempt' => 1,
            'started_at' => now()->subHour(), 'lease_until' => now()->subMinutes(30),
            'input_json' => ['expected_output' => ['disk' => 'local', 'path' => 'smoke.out']],
        ]);

        $report = app(PipelineRecovery::class)->recover(boot: true);

        $this->assertSame(1, $report['recovered_outputs']);
        $this->assertSame(1, $report['redispatched']);
        $run->refresh();
        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(1, $run->steps()->count(), 'SmokeStep saw a succeeded step and did not run its work again');
    }

    public function test_size_must_match_when_declared(): void
    {
        Storage::disk('local')->put('a.mp4', '12345');

        $wrong = $this->deadStep(['disk' => 'local', 'path' => 'a.mp4', 'bytes' => 999]);
        app(PipelineRecovery::class)->recover();
        $this->assertSame(StepStatus::Failed, $wrong->fresh()->status, 'half-written file must not count');

        $right = $this->deadStep(['disk' => 'local', 'path' => 'a.mp4', 'bytes' => 5], ContentPipelineRun::start(null, 'test'));
        app(PipelineRecovery::class)->recover();
        $this->assertSame(StepStatus::Succeeded, $right->fresh()->status);
    }

    public function test_checksum_must_match_when_declared(): void
    {
        Storage::disk('local')->put('a.png', 'hello');

        $wrong = $this->deadStep(['disk' => 'local', 'path' => 'a.png', 'sha256' => str_repeat('0', 64)]);
        app(PipelineRecovery::class)->recover();
        $this->assertSame(StepStatus::Failed, $wrong->fresh()->status);

        $right = $this->deadStep(['disk' => 'local', 'path' => 'a.png', 'sha256' => hash('sha256', 'hello')], ContentPipelineRun::start(null, 'test'));
        app(PipelineRecovery::class)->recover();
        $this->assertSame(StepStatus::Succeeded, $right->fresh()->status);
    }

    public function test_disks_outside_the_allow_list_are_refused(): void
    {
        Storage::fake('social_private');
        Storage::disk('social_private')->put('secret.txt', 'private');
        $step = $this->deadStep(['disk' => 'social_private', 'path' => 'secret.txt']);

        app(PipelineRecovery::class)->recover();

        $this->assertSame(StepStatus::Failed, $step->fresh()->status);
        $this->assertSame(1, ContentEvent::where('action', 'step.probe_rejected')->count());
    }

    public function test_path_traversal_and_absolute_paths_are_refused(): void
    {
        Storage::disk('local')->put('inside.txt', 'x');

        foreach (['../inside.txt', 'a/../inside.txt', '/inside.txt', 'a\\b', ''] as $i => $path) {
            $step = $this->deadStep(['disk' => 'local', 'path' => $path], ContentPipelineRun::start(null, 'test'));
            app(PipelineRecovery::class)->recover();
            $this->assertSame(StepStatus::Failed, $step->fresh()->status, "path {$path} must not be trusted");
        }
        $this->assertSame(5, ContentEvent::where('action', 'step.probe_rejected')->count());
    }

    public function test_unreachable_storage_falls_back_to_running_the_step_again(): void
    {
        Storage::shouldReceive('disk')->with('local')->andThrow(new RuntimeException('minio down'));
        $step = $this->deadStep(['disk' => 'local', 'path' => 'x.png']);

        app(PipelineRecovery::class)->recover();

        $this->assertSame(StepStatus::Failed, $step->fresh()->status);
        $this->assertSame(1, ContentEvent::where('action', 'step.probe_failed')->count());
    }
}
