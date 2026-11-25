<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Enums\ContentStudio\RunStatus;
use App\Enums\ContentStudio\StepStatus;
use App\Jobs\ContentStudio\ProcessContentItem;
use App\Models\ContentStudio\ContentAsset;
use App\Models\ContentStudio\ContentEvent;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Models\ContentStudio\ContentPipelineStep;
use App\Services\ContentStudio\Ops\AutomationGate;
use App\Services\ContentStudio\Pipeline\ContentPipelineStarter;
use App\Services\ContentStudio\Pipeline\PipelineRecovery;
use App\Services\ContentStudio\Contracts\CarouselRenderer;
use App\Services\ContentStudio\Render\CarouselProducer;
use App\Services\ContentStudio\Render\CarouselSpec;
use App\Services\ContentStudio\Render\GdCarouselRenderer;
use App\Services\ContentStudio\State\ContentStateMachine;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesContentItems;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class ProcessContentItemTest extends TestCase
{
    use MakesContentItems;
    use UsesContentStudioDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeStorage();
    }

    private function start(ContentItem $item): ContentPipelineRun
    {
        return app(ContentPipelineStarter::class)->start($item);
    }

    /** @return list<string> */
    private function stepNames(ContentPipelineRun $run): array
    {
        return $run->steps()->orderBy('id')->pluck('step_name')->all();
    }

    public function test_clean_content_goes_all_the_way_to_ready_and_is_exported(): void
    {
        $item = $this->cleanItem();

        $run = $this->start($item);

        $item->refresh();
        $this->assertSame(ContentStatus::Ready, $item->status);
        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(['verify', 'render', 'quality_check', 'export'], $this->stepNames($run));
        $this->assertSame([StepStatus::Succeeded], $run->steps()->pluck('status')->unique()->all());

        // the files exist and the manifest, QA report and export package were written
        $producer = app(CarouselProducer::class);
        $this->assertNotNull($producer->readManifest($item, 1));
        Storage::disk('local')->assertExists("content-studio/items/{$item->id}/v1/qa.json");
        $this->assertSame(5, ContentAsset::where('kind', 'carousel_slide')->count());
        $dir = collect(Storage::disk('local')->allDirectories('content-studio/exports'))->first(fn ($d) => str_contains($d, "/{$item->id}-"));
        $this->assertNotNull($dir, 'export package directory');
        foreach (['01.png', '05.png', 'caption.txt', 'qa.json', 'report.json'] as $file) {
            Storage::disk('local')->assertExists("{$dir}/{$file}");
        }
        $this->assertNull($item->scheduled_at, 'exporting is not scheduling');
    }

    public function test_every_status_change_is_a_logged_transition_in_order(): void
    {
        $item = $this->cleanItem();
        $this->start($item);

        $moves = ContentEvent::where('action', 'content.transition')->where('entity_id', (string) $item->id)->orderBy('id')->get()
            ->map(fn ($e) => $e->payload_redacted['from'].'>'.$e->payload_redacted['to'])->all();

        $this->assertSame([
            'idea>researching', 'researching>source_verified', 'source_verified>scripted', 'scripted>producing',
            'producing>quality_check', 'quality_check>ready',
        ], $moves);
    }

    public function test_content_the_reviewer_must_look_at_stops_before_export(): void
    {
        $item = $this->cleanItem('tutorial', ['caption' => 'Bài này tốt nhất cho người mới chơi cầu lông.']);

        $run = $this->start($item);

        $item->refresh();
        $this->assertSame(ContentStatus::NeedsReview, $item->status);
        $this->assertStringContainsString('claims', $item->blocked_reason);
        $this->assertSame(['verify', 'render', 'quality_check'], $this->stepNames($run), 'no export for unreviewed content');
        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status, 'the run ended cleanly; the verdict is a result, not an error');
        $this->assertSame([], Storage::disk('local')->allDirectories('content-studio/exports'));
    }

    public function test_mock_output_can_never_reach_ready(): void
    {
        $item = $this->cleanItem('tutorial', ['brief_json' => ['plan_date' => '2026-11-08', 'render_version' => 1, 'mock' => true, 'review_reason' => 'MOCK AI output']]);

        $this->start($item);

        $this->assertSame(ContentStatus::NeedsReview, $item->fresh()->status);
        $this->assertStringContainsString('MOCK', $item->fresh()->blocked_reason);
    }

    public function test_a_news_post_without_an_official_source_is_blocked_before_any_rendering(): void
    {
        $item = $this->cleanItem('news');

        $run = $this->start($item);

        $this->assertSame(ContentStatus::Blocked, $item->fresh()->status);
        $this->assertStringContainsString('news_source_invalid', $item->fresh()->blocked_reason);
        $this->assertSame(['verify'], $this->stepNames($run), 'nothing was rendered for a post that can never be published');
        $this->assertSame(0, ContentAsset::count());
    }

    public function test_a_news_post_built_on_a_stale_source_is_blocked(): void
    {
        $item = $this->newsItem($this->newsSource(['published_at' => now()->subDays(90)]));

        $this->start($item);

        $this->assertSame(ContentStatus::Blocked, $item->fresh()->status);
    }

    public function test_a_valid_news_post_is_ready_with_the_credit_on_every_slide(): void
    {
        $item = $this->newsItem();

        $this->start($item);

        $this->assertSame(ContentStatus::Ready, $item->fresh()->status);
    }

    public function test_text_that_can_never_fit_fails_once_and_does_not_burn_the_retries(): void
    {
        $slides = $this->cleanSlides();
        $slides[1]['body'] = trim(str_repeat('Đoạn văn rất dài không thể nhét vừa một slide. ', 60));
        $item = $this->cleanItem('tutorial', ['script_json' => ['slides' => $slides]]);

        $run = $this->start($item);

        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        $this->assertSame(ContentStatus::Failed, $item->fresh()->status);
        $this->assertSame(1, ContentPipelineStep::where('run_id', $run->id)->where('step_name', 'render')->count(), 'one attempt: retrying cannot help');
        $this->assertStringContainsString('does not fit', $item->fresh()->blocked_reason);
    }

    public function test_delivering_the_same_job_twice_does_the_work_once(): void
    {
        $item = $this->cleanItem();
        $run = $this->start($item);
        $generated = app(CarouselProducer::class)->readManifest($item->fresh(), 1)['generated_at'];

        dispatch(new ProcessContentItem($run->id)); // the queue delivers it again

        $this->assertSame(4, $run->steps()->count(), 'no step ran twice');
        $this->assertSame(ContentStatus::Ready, $item->fresh()->status);
        $this->assertSame($generated, app(CarouselProducer::class)->readManifest($item->fresh(), 1)['generated_at'], 'not rendered again');
        $this->assertSame(1, ContentEvent::where('action', 'content.exported')->count());
    }

    public function test_an_item_cancelled_while_waiting_is_left_alone(): void
    {
        $item = $this->cleanItem();
        $item = app(ContentStateMachine::class)->transition($item, ContentStatus::Cancelled);

        $run = $this->start($item);

        $this->assertSame(ContentStatus::Cancelled, $item->fresh()->status);
        $this->assertSame(0, ContentAsset::count(), 'nothing rendered for a cancelled item');
        $this->assertSame([], $this->stepNames($run), 'no work at all');
        $this->assertSame(RunStatus::Cancelled, $run->fresh()->status, 'the run is closed, so recovery never re-sends it');
        $this->assertNull($run->fresh()->resume_job_json);
    }

    public function test_an_item_cancelled_while_the_pipeline_runs_ends_the_run_instead_of_leaving_it_open(): void
    {
        $item = $this->cleanItem();
        $itemId = $item->id;

        // An admin cancels the post while it is being rendered: the renderer cancels it as a side effect.
        $this->app->bind(GdCarouselRenderer::class, fn () => new class($itemId) implements CarouselRenderer
        {
            public function __construct(private int $itemId) {}

            public function name(): string
            {
                return 'gd';
            }

            public function render(CarouselSpec $spec, string $outDir): array
            {
                $item = ContentItem::findOrFail($this->itemId);
                app(ContentStateMachine::class)->transition($item, ContentStatus::Cancelled);

                return (new GdCarouselRenderer())->render($spec, $outDir);
            }
        });

        $run = $this->start($item);

        $this->assertSame(ContentStatus::Cancelled, $item->fresh()->status, 'the admin decision stands');
        $this->assertSame(RunStatus::Cancelled, $run->fresh()->status, 'the run is closed instead of being left open for recovery to re-send');
        $this->assertNull($run->fresh()->resume_job_json);
        $this->assertNotContains('quality_check', $this->stepNames($run), 'the pipeline stopped after the render');
        $this->assertSame(0, ContentEvent::where('action', 'content.exported')->count());
    }

    public function test_a_second_run_is_never_started_while_one_is_unfinished(): void
    {
        $this->app['config']->set('content-studio.queues.connection', 'redis'); // keep the first run pending: nothing consumes redis here
        \Illuminate\Support\Facades\Queue::fake();
        $item = $this->cleanItem();

        $a = app(ContentPipelineStarter::class)->start($item);
        $b = app(ContentPipelineStarter::class)->start($item);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, ContentPipelineRun::count());
    }

    public function test_pausing_holds_the_pipeline_and_resuming_finishes_it(): void
    {
        app(AutomationGate::class)->pause('tester', 'maintenance');
        $item = $this->cleanItem();

        $run = $this->start($item);
        $this->assertSame(RunStatus::Pending, $run->fresh()->status, 'paused: not a single step started');
        $this->assertSame(ContentStatus::Idea, $item->fresh()->status);

        app(AutomationGate::class)->resume('tester');
        $run->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        app(PipelineRecovery::class)->recover();

        $this->assertSame(ContentStatus::Ready, $item->fresh()->status);
        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
    }

    public function test_a_worker_that_died_after_rendering_does_not_render_again(): void
    {
        $item = $this->cleanItem();
        $states = app(ContentStateMachine::class);
        $item = $states->advanceTo($item, ContentStatus::Producing);

        // the render finished and wrote its manifest, then the worker was killed before it could record success
        $producer = app(CarouselProducer::class);
        $before = $producer->produce($item)['manifest']['generated_at'];
        $run = ContentPipelineRun::start($item, 'carousel');
        $run->forceFill(['resume_job_json' => ['class' => ProcessContentItem::class, 'args' => [$run->id]], 'updated_at' => now()->subHour()])->saveQuietly();
        ContentPipelineStep::create([
            'run_id' => $run->id, 'step_name' => 'verify', 'status' => StepStatus::Succeeded, 'attempt' => 1,
            'output_json' => ['verdict' => 'ok', 'reason' => null],
        ]);
        ContentPipelineStep::create([
            'run_id' => $run->id, 'step_name' => 'render', 'status' => StepStatus::Running, 'attempt' => 1,
            'started_at' => now()->subHour(), 'lease_until' => now()->subMinutes(30),
            'input_json' => ['expected_output' => ['disk' => 'local', 'path' => $producer->manifestPath($item, 1)]],
        ]);

        $report = app(PipelineRecovery::class)->recover(boot: true);

        $this->assertSame(1, $report['recovered_outputs']);
        $this->assertSame($before, $producer->readManifest($item->fresh(), 1)['generated_at'], 'the existing render was kept');
        $this->assertSame(ContentStatus::Ready, $item->fresh()->status);
        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(1, $run->steps()->where('step_name', 'render')->count(), 'render was not attempted again');
    }
}
