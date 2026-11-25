<?php

namespace App\Services\ContentStudio\Review;

use App\Enums\ContentStudio\ContentStatus;
use App\Enums\ContentStudio\RunStatus;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Services\ContentStudio\Ops\ContentEventLogger;
use App\Services\ContentStudio\Pipeline\ContentPipelineStarter;
use App\Services\ContentStudio\Publishers\ManualExportPublisher;
use App\Services\ContentStudio\Quality\QualityGate;
use App\Services\ContentStudio\Quality\QualityReport;
use App\Services\ContentStudio\Render\CarouselProducer;
use App\Services\ContentStudio\State\ContentStateMachine;
use App\Services\ContentStudio\State\StaleContentItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What a person may do with an item: approve, retry, cancel. The CLI and the admin
 * API both go through here, so the same rules and audit trail apply to both.
 * Nothing here posts anything.
 */
final class ContentReview
{
    public function __construct(
        private readonly QualityGate $gate,
        private readonly CarouselProducer $producer,
        private readonly ContentStateMachine $states,
        private readonly ManualExportPublisher $exporter,
        private readonly ContentPipelineStarter $starter,
        private readonly ContentEventLogger $events,
    ) {}

    /**
     * The fresh quality report of an item that may be approved.
     *
     * @throws ReviewException when no reviewer may approve it
     */
    public function assertApprovable(ContentItem $item, ?QualityReport $report = null): QualityReport
    {
        if ($item->status !== ContentStatus::NeedsReview) {
            throw new ReviewException(ReviewException::NOT_IN_REVIEW, "Only an item in needs_review can be approved (item {$item->id} is {$item->status->value}).");
        }
        if (! empty($item->brief_json['mock'])) {
            throw new ReviewException(ReviewException::MOCK, 'This item was produced by the MOCK AI client. Placeholder content is never approvable.');
        }
        if ($this->producer->readManifest($item, $this->producer->currentVersion($item)) === null) {
            throw new ReviewException(ReviewException::NO_RENDER, 'The item has no complete render. Run content:render first.');
        }

        $report ??= $this->gate->evaluate($item);
        $blocking = array_values(array_filter($report->failed(), fn ($c) => $c['severity'] === 'block'));
        if ($blocking !== []) {
            throw new ReviewException(ReviewException::BLOCKING_FINDINGS, 'Cannot approve: these problems cannot be waived by a reviewer.', $blocking);
        }

        return $report;
    }

    /**
     * Move a reviewed item to ready and write its export package.
     *
     * @param  list<string>|null  $accepted  finding codes the reviewer saw; null = whatever is found now
     * @return array{findings: list<array<string, mixed>>, directory: ?string, export_error: ?string}
     *
     * @throws ReviewException
     * @throws StaleContentItem
     */
    public function approve(ContentItem $item, string $reason, string $actor, ?array $accepted = null): array
    {
        $report = $this->assertApprovable($item);
        $findings = $report->failed();
        $codes = array_column($findings, 'code');

        if ($accepted !== null && array_diff($codes, $accepted) !== []) {
            throw new ReviewException(ReviewException::FINDINGS_CHANGED, 'The quality findings changed since you read them. Review the item again.', $findings);
        }
        $reason = trim($reason);
        if ($findings !== [] && $reason === '') {
            throw new ReviewException(ReviewException::REASON_REQUIRED, 'A reason is required: say why the findings are acceptable.', $findings);
        }

        $this->states->transition($item, ContentStatus::Ready, null, $actor);
        $this->events->log('content.approved', 'content_item', $item->id, ['reason' => $reason, 'accepted' => $codes], $actor);

        try {
            $directory = $this->exporter->export($item->refresh())['directory'];
        } catch (RuntimeException $e) {
            return ['findings' => $findings, 'directory' => null, 'export_error' => $this->events->scrub($e->getMessage())];
        }

        return ['findings' => $findings, 'directory' => $directory, 'export_error' => null];
    }

    /**
     * Produce a failed item again, in a new run.
     *
     * @throws ReviewException
     */
    public function retry(ContentItem $item, string $actor): ContentPipelineRun
    {
        if ($item->status !== ContentStatus::Failed) {
            throw new ReviewException(ReviewException::NOT_FAILED, "Only a failed item can be retried (item {$item->id} is {$item->status->value}).");
        }

        $item = $this->states->transition($item, ContentStatus::Producing, null, $actor);
        $this->events->log('content.retried', 'content_item', $item->id, [], $actor);

        return $this->starter->start($item);
    }

    /** A scheduled post lives at the provider too, so it cannot be cancelled from here. */
    public function canCancel(ContentItem $item): bool
    {
        return $item->status !== ContentStatus::Scheduled && $item->status->canTransitionTo(ContentStatus::Cancelled);
    }

    /**
     * Stop all planned work on an item. Cannot be undone.
     *
     * @throws ReviewException
     */
    public function cancel(ContentItem $item, string $reason, string $actor): void
    {
        if (! $this->canCancel($item)) {
            throw new ReviewException(ReviewException::NOT_CANCELLABLE, "Item {$item->id} cannot be cancelled while it is {$item->status->value}.");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ReviewException(ReviewException::REASON_REQUIRED, 'A reason is required to cancel an item.');
        }

        DB::transaction(function () use ($item, $reason, $actor) {
            $this->states->transition($item, ContentStatus::Cancelled, $reason, $actor);
            // close open runs now, otherwise content:recover keeps re-sending them
            $item->runs()
                ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
                ->get()
                ->each(fn (ContentPipelineRun $run) => $run->markFinished(RunStatus::Cancelled, 'The item was cancelled.'));
            $this->events->log('content.cancelled', 'content_item', $item->id, ['reason' => $reason], $actor);
        });
    }
}
