<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Models\ContentStudio\ContentEvent;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Models\ContentStudio\ContentPlan;
use App\Services\ContentStudio\Pipeline\ContentPipelineStarter;
use App\Services\ContentStudio\Render\CarouselProducer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesContentItems;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class ContentWorkflowCommandsTest extends TestCase
{
    use MakesContentItems;
    use UsesContentStudioDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeStorage();
    }

    /** An item the gate sends to review (an unsupported claim), already rendered. */
    private function reviewItem(array $over = []): ContentItem
    {
        $item = $this->cleanItem('tutorial', $over + ['caption' => 'Bài viết này tốt nhất cho người chơi mới.']);
        app(ContentPipelineStarter::class)->start($item);
        $this->assertSame(ContentStatus::NeedsReview, $item->fresh()->status);

        return $item->fresh();
    }

    // ---- content:plan ------------------------------------------------------------

    public function test_plan_dry_run_with_the_mock_writes_nothing(): void
    {
        $this->artisan('content:plan --date=2026-11-08 --mock-ai --dry-run')
            ->expectsOutputToContain('status: dry_run')
            ->expectsOutputToContain('equipment')
            ->assertExitCode(0);

        $this->assertSame(0, ContentPlan::count());
        $this->assertSame(0, ContentItem::count());
        $this->assertSame(0, ContentPipelineRun::count());
    }

    public function test_plan_fails_closed_when_ai_is_not_enabled_and_writes_nothing(): void
    {
        config(['content-studio.ai.enabled' => false]);

        $this->artisan('content:plan --date=2026-11-08')
            ->expectsOutputToContain('AI unavailable [disabled]')
            ->expectsOutputToContain('Nothing was written')
            ->assertExitCode(1);

        $this->assertSame(0, ContentPlan::count());
    }

    public function test_plan_with_the_mock_creates_items_and_runs_their_pipelines_but_mock_content_stops_at_review(): void
    {
        $this->artisan('content:plan --date=2026-11-08 --mock-ai')
            ->expectsOutputToContain('pipelines started: 2')
            ->assertExitCode(0);

        $items = ContentItem::orderBy('id')->get();
        $this->assertEqualsCanonicalizing(['equipment', 'tutorial'], $items->pluck('slot')->map->value->all());
        foreach ($items as $item) {
            $this->assertSame(ContentStatus::NeedsReview, $item->status, 'mock output never becomes ready');
            $this->assertStringContainsString('MOCK', $item->blocked_reason);
        }
        $this->assertSame(10, \App\Models\ContentStudio\ContentAsset::where('kind', 'carousel_slide')->count(), 'two items x five slides, really rendered');
        $this->assertSame([], Storage::disk('local')->allDirectories('content-studio/exports'), 'nothing exported');
    }

    public function test_plan_no_dispatch_only_saves(): void
    {
        $this->artisan('content:plan --date=2026-11-08 --mock-ai --no-dispatch')->assertExitCode(0);

        $this->assertSame(2, ContentItem::count());
        $this->assertSame(0, ContentPipelineRun::count());
        $this->assertSame(['idea', 'idea'], ContentItem::pluck('status')->map->value->all());
    }

    public function test_running_the_plan_again_does_not_duplicate_anything(): void
    {
        $this->artisan('content:plan --date=2026-11-08 --mock-ai')->assertExitCode(0);
        $this->artisan('content:plan --date=2026-11-08 --mock-ai')->assertExitCode(0);

        $this->assertSame(2, ContentItem::count());
        $this->assertSame(1, ContentPlan::count());
        $this->assertSame(2, ContentPipelineRun::count());
    }

    // ---- render / qc / export ------------------------------------------------------

    public function test_render_qc_and_export_commands_report_clearly(): void
    {
        $item = $this->cleanItem();

        $this->artisan('content:qc --content-id='.$item->id)->expectsOutputToContain('verdict: blocked')->assertExitCode(1);
        $this->artisan('content:render --content-id='.$item->id)->expectsOutputToContain('Rendered v1')->assertExitCode(0);
        $this->artisan('content:render --content-id='.$item->id)->expectsOutputToContain('Already rendered v1')->assertExitCode(0);
        $this->artisan('content:render --content-id='.$item->id.' --force')->expectsOutputToContain('Rendered v2')->assertExitCode(0);
        $this->artisan('content:export --content-id='.$item->id)->expectsOutputToContain('Only a ready item')->assertExitCode(1);
    }

    public function test_commands_complain_about_a_missing_or_unknown_id(): void
    {
        foreach (['content:render', 'content:qc', 'content:export', 'content:approve', 'content:retry'] as $command) {
            $this->artisan($command)->expectsOutputToContain('Pass --content-id')->assertExitCode(1);
            $this->artisan("{$command} --content-id=99999")->expectsOutputToContain('No content item with id 99999')->assertExitCode(1);
        }
    }

    // ---- approve -------------------------------------------------------------------------

    public function test_approving_needs_a_reason_when_there_are_findings(): void
    {
        $item = $this->reviewItem();

        $this->artisan('content:approve --content-id='.$item->id)
            ->expectsOutputToContain('claims')
            ->expectsOutputToContain('--reason')
            ->assertExitCode(1);
        $this->assertSame(ContentStatus::NeedsReview, $item->fresh()->status);
    }

    public function test_approving_with_a_reason_makes_it_ready_audits_it_and_exports(): void
    {
        $item = $this->reviewItem();

        $this->artisan('content:approve', ['--content-id' => $item->id, '--reason' => 'Đã đọc lại, cách nói này chấp nhận được'])
            ->expectsOutputToContain('approved')
            ->assertExitCode(0);

        $this->assertSame(ContentStatus::Ready, $item->fresh()->status);
        $event = ContentEvent::where('action', 'content.approved')->sole();
        $this->assertSame('cli', $event->actor);
        $this->assertSame('Đã đọc lại, cách nói này chấp nhận được', $event->payload_redacted['reason']);
        $this->assertSame(['claims'], $event->payload_redacted['accepted'], 'the audit trail says exactly which findings were waived');
        $this->assertNotEmpty(Storage::disk('local')->allDirectories('content-studio/exports'));
    }

    public function test_mock_content_can_never_be_approved(): void
    {
        $this->artisan('content:plan --date=2026-11-08 --mock-ai')->assertExitCode(0);
        $mock = ContentItem::first();

        $this->artisan('content:approve', ['--content-id' => $mock->id, '--reason' => 'try'])
            ->expectsOutputToContain('MOCK')
            ->assertExitCode(1);
        $this->assertSame(ContentStatus::NeedsReview, $mock->fresh()->status);
    }

    public function test_problems_a_reviewer_cannot_waive_refuse_the_approval(): void
    {
        $item = $this->reviewItem();
        Storage::disk('local')->put("content-studio/items/{$item->id}/v1/02.png", 'tampered');

        $this->artisan('content:approve', ['--content-id' => $item->id, '--reason' => 'ok'])
            ->expectsOutputToContain('cannot be waived')
            ->assertExitCode(1);
        $this->assertSame(ContentStatus::NeedsReview, $item->fresh()->status);
    }

    public function test_only_items_waiting_for_review_can_be_approved(): void
    {
        $ready = $this->cleanItem();
        app(ContentPipelineStarter::class)->start($ready);
        $this->assertSame(ContentStatus::Ready, $ready->fresh()->status);

        $this->artisan('content:approve --content-id='.$ready->id)->expectsOutputToContain('needs_review')->assertExitCode(1);
    }

    public function test_an_unrendered_review_item_cannot_be_approved(): void
    {
        $item = $this->cleanItem();
        app(\App\Services\ContentStudio\State\ContentStateMachine::class)->advanceTo($item, ContentStatus::QualityCheck);
        app(\App\Services\ContentStudio\State\ContentStateMachine::class)->transition($item->fresh(), ContentStatus::NeedsReview, 'manual');

        $this->artisan('content:approve --content-id='.$item->id)->expectsOutputToContain('no complete render')->assertExitCode(1);
    }

    // ---- retry ----------------------------------------------------------------------------

    public function test_retry_runs_a_failed_item_again_after_its_text_was_fixed(): void
    {
        $slides = $this->cleanSlides();
        $slides[1]['body'] = trim(str_repeat('Đoạn văn rất dài không thể nhét vừa một slide. ', 60));
        $item = $this->cleanItem('tutorial', ['script_json' => ['slides' => $slides]]);
        app(ContentPipelineStarter::class)->start($item);
        $this->assertSame(ContentStatus::Failed, $item->fresh()->status);

        $item->update(['script_json' => ['slides' => $this->cleanSlides()]]); // a human fixed the text
        $this->artisan('content:retry --content-id='.$item->id)->expectsOutputToContain('producing again')->assertExitCode(0);

        $this->assertSame(ContentStatus::Ready, $item->fresh()->status);
        $this->assertSame(2, ContentPipelineRun::where('content_item_id', $item->id)->count(), 'a new run, the failed one is kept as history');
    }

    public function test_only_failed_items_can_be_retried(): void
    {
        $ready = $this->cleanItem();
        app(ContentPipelineStarter::class)->start($ready);

        $this->artisan('content:retry --content-id='.$ready->id)->expectsOutputToContain('Only a failed item')->assertExitCode(1);
        $this->assertNotNull(app(CarouselProducer::class)->readManifest($ready->fresh(), 1));
    }
}
