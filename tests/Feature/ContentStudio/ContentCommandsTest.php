<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\RunStatus;
use App\Models\ContentStudio\ContentPipelineRun;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class ContentCommandsTest extends TestCase
{
    use UsesContentStudioDatabase;

    public function test_smoke_command_runs_a_job_through_the_queue(): void
    {
        config(['content-studio.queues.connection' => 'sync']);

        $this->artisan('content:smoke --wait=5')->expectsOutputToContain('OK')->assertExitCode(0);

        $run = ContentPipelineRun::sole();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame('system.smoke', $run->pipeline);
        $this->assertNull($run->content_item_id);
    }

    public function test_recover_command_reports_counts(): void
    {
        $this->artisan('content:recover --boot')
            ->expectsOutputToContain('expired_steps=0 recovered_outputs=0 redispatched=0 rejected=0')
            ->assertExitCode(0);
    }
}
