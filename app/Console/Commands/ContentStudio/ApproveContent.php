<?php

namespace App\Console\Commands\ContentStudio;

use App\Console\Commands\ContentStudio\Concerns\FindsContentItem;
use App\Enums\ContentStudio\ContentStatus;
use App\Services\ContentStudio\Ops\ContentEventLogger;
use App\Services\ContentStudio\Publishers\ManualExportPublisher;
use App\Services\ContentStudio\Quality\QualityGate;
use App\Services\ContentStudio\Render\CarouselProducer;
use App\Services\ContentStudio\State\ContentStateMachine;
use Illuminate\Console\Command;
use RuntimeException;

class ApproveContent extends Command
{
    use FindsContentItem;

    protected $signature = 'content:approve {--content-id= : Content item id} {--reason= : Why you accept the review findings (required)}';

    protected $description = 'Approve an item that is waiting for review: moves it to ready and writes its export package';

    public function handle(QualityGate $gate, CarouselProducer $producer, ContentStateMachine $states, ManualExportPublisher $exporter, ContentEventLogger $events): int
    {
        if (($item = $this->contentItem()) === null) {
            return self::FAILURE;
        }

        if ($item->status !== ContentStatus::NeedsReview) {
            $this->error("Only an item in needs_review can be approved (item {$item->id} is {$item->status->value}).");

            return self::FAILURE;
        }
        if (! empty($item->brief_json['mock'])) {
            $this->error('This item was produced by the MOCK AI client. Placeholder content is never approvable.');

            return self::FAILURE;
        }
        if ($producer->readManifest($item, $producer->currentVersion($item)) === null) {
            $this->error('The item has no complete render. Run content:render first.');

            return self::FAILURE;
        }

        $report = $gate->evaluate($item);
        $blocking = array_filter($report->failed(), fn ($c) => $c['severity'] === 'block');
        if ($blocking !== []) {
            $this->error('Cannot approve: these problems cannot be waived by a reviewer:');
            foreach ($blocking as $c) {
                $this->line("  - {$c['code']}: {$c['message']}");
            }

            return self::FAILURE;
        }

        $this->line('Review findings you are accepting:');
        foreach ($report->failed() as $c) {
            $this->warn("  - {$c['code']}: {$c['message']}");
        }

        $reason = trim((string) $this->option('reason'));
        if ($report->failed() !== [] && $reason === '') {
            $this->error('Findings exist, so --reason="..." is required: say why they are acceptable.');

            return self::FAILURE;
        }

        $states->transition($item, ContentStatus::Ready, null, 'cli');
        $events->log('content.approved', 'content_item', $item->id, ['reason' => $reason, 'accepted' => array_column($report->failed(), 'code')], 'cli');

        try {
            $result = $exporter->export($item->refresh());
        } catch (RuntimeException $e) {
            $this->error('Approved, but the export failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Item {$item->id} approved. Package: {$result['directory']}. Nothing has been posted.");

        return self::SUCCESS;
    }
}
