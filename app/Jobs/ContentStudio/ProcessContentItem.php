<?php

namespace App\Jobs\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Enums\ContentStudio\RunStatus;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Services\ContentStudio\Pipeline\CarouselPipeline;
use App\Services\ContentStudio\Pipeline\StepRunner;
use App\Services\ContentStudio\State\ContentStateMachine;
use App\Services\ContentStudio\State\InvalidContentTransition;
use App\Services\ContentStudio\State\StaleContentItem;

/**
 * Produces ONE carousel end to end: verify -> render -> quality check -> export.
 *
 * Every step goes through StepRunner, so the whole job can be delivered twice,
 * killed half way or re-sent by content:recover and still do each thing once.
 * A step's verdict ("blocked", "needs review") is a normal result, not an exception:
 * the item is settled and the run ends cleanly.
 *
 * With the playwright driver the job runs on the media queue (the only place
 * Chromium exists); with gd it runs on the normal content queue.
 */
class ProcessContentItem extends ContentStepJob
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct();
        $this->timeout = config('content-studio.render.driver') === 'playwright' ? 900 : 180;
    }

    protected function route(): void
    {
        if (config('content-studio.render.driver') === 'playwright') {
            $this->onConnection(config('content-studio.queues.media_connection'))->onQueue(config('content-studio.queues.media'));

            return;
        }
        parent::route();
    }

    public function handle(StepRunner $runner, CarouselPipeline $pipeline, ContentStateMachine $states): void
    {
        $run = ContentPipelineRun::findOrFail($this->runId);
        $item = $run->item;
        if ($item === null || $run->status->isFinished()) {
            return;
        }
        if ($item->status->isTerminal()) {
            $this->abandon($run);

            return;
        }
        $trace = $run->trace_id;

        // 1. verify -----------------------------------------------------------------
        $verify = $this->execute($runner, $run, 'verify', fn () => $pipeline->verify($item));
        if (! $verify->isCompleted()) {
            return;
        }
        if ($verify->output['verdict'] !== 'ok') {
            $this->settle($states, $item, ContentStatus::Blocked, (string) $verify->output['reason'], $run);

            return;
        }
        $item = $this->advance($states, $item, ContentStatus::Scripted, $trace);
        if ($item === null) {
            $this->abandon($run);

            return;
        }

        // 2. render -----------------------------------------------------------------
        $item = $this->advance($states, $item, ContentStatus::Producing, $trace);
        if ($item === null) {
            $this->abandon($run);

            return;
        }
        $render = $this->execute($runner, $run, 'render', fn () => $pipeline->render($item), ['expected_output' => $pipeline->expectedRenderOutput($item)]);
        if (! $render->isCompleted()) {
            return;
        }

        // 3. quality check ----------------------------------------------------------------
        $item = $this->advance($states, $item, ContentStatus::QualityCheck, $trace);
        if ($item === null) {
            $this->abandon($run);

            return;
        }
        $quality = $this->execute($runner, $run, 'quality_check', fn () => $pipeline->quality($item));
        if (! $quality->isCompleted()) {
            return;
        }
        $verdict = $quality->output['verdict'];
        if ($verdict !== 'ready') {
            $status = $verdict === 'needs_review' ? ContentStatus::NeedsReview : ContentStatus::Blocked;
            $this->settle($states, $item, $status, (string) $quality->output['summary'], $run);

            return;
        }
        $item = $this->advance($states, $item, ContentStatus::Ready, $trace);
        if ($item === null) {
            $this->abandon($run);

            return;
        }

        // 4. export ---------------------------------------------------------------------------
        $export = $this->execute($runner, $run, 'export', fn () => $pipeline->export($item));
        if ($export->isCompleted()) {
            $run->fresh()->markFinished(RunStatus::Succeeded);
        }
    }

    /**
     * The item was cancelled or moved by someone else: end the run. Leaving it "running"
     * would make content:recover re-send this job forever.
     */
    private function abandon(ContentPipelineRun $run): void
    {
        $fresh = $run->fresh();
        if ($fresh !== null && ! $fresh->status->isFinished()) {
            $fresh->markFinished(RunStatus::Cancelled, 'The item was cancelled or changed by someone else.');
        }
    }

    /** Walk the item forward; null if it was cancelled or changed under us, so the job just stops. */
    private function advance(ContentStateMachine $states, ContentItem $item, ContentStatus $target, string $trace): ?ContentItem
    {
        $item = $item->fresh();
        if ($item === null || $item->status->isTerminal()) {
            return null;
        }

        try {
            return $states->advanceTo($item, $target, 'system', $trace);
        } catch (StaleContentItem|InvalidContentTransition) {
            return null; // an admin moved it (cancel, approve...): do not fight them
        }
    }

    /** Put the item into its final state for this run and end the run cleanly. */
    private function settle(ContentStateMachine $states, ContentItem $item, ContentStatus $status, string $reason, ContentPipelineRun $run): void
    {
        $item = $item->fresh();
        if ($item !== null && ! $item->status->isTerminal()) {
            try {
                $states->settle($item, $status, $reason, 'system', $run->trace_id);
            } catch (StaleContentItem|InvalidContentTransition) {
                // already moved by someone else
            }
        }
        $run->fresh()->markFinished(RunStatus::Succeeded);
    }
}
