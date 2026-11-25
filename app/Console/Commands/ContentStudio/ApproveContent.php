<?php

namespace App\Console\Commands\ContentStudio;

use App\Console\Commands\ContentStudio\Concerns\FindsContentItem;
use App\Services\ContentStudio\Review\ContentReview;
use App\Services\ContentStudio\Review\ReviewException;
use Illuminate\Console\Command;

class ApproveContent extends Command
{
    use FindsContentItem;

    protected $signature = 'content:approve {--content-id= : Content item id} {--reason= : Why you accept the review findings (required)}';

    protected $description = 'Approve an item that is waiting for review: moves it to ready and writes its export package';

    public function handle(ContentReview $review): int
    {
        if (($item = $this->contentItem()) === null) {
            return self::FAILURE;
        }

        try {
            $report = $review->assertApprovable($item);

            $this->line('Review findings you are accepting:');
            foreach ($report->failed() as $c) {
                $this->warn("  - {$c['code']}: {$c['message']}");
            }

            $result = $review->approve($item, (string) $this->option('reason'), 'cli', array_column($report->failed(), 'code'));
        } catch (ReviewException $e) {
            if ($e->reasonCode === ReviewException::REASON_REQUIRED) {
                $this->error('Findings exist, so --reason="..." is required: say why they are acceptable.');

                return self::FAILURE;
            }
            $this->error($e->getMessage());
            foreach ($e->findings as $c) {
                $this->line("  - {$c['code']}: {$c['message']}");
            }

            return self::FAILURE;
        }

        if ($result['export_error'] !== null) {
            $this->error('Approved, but the export failed: '.$result['export_error']);

            return self::FAILURE;
        }

        $this->info("Item {$item->id} approved. Package: {$result['directory']}. Nothing has been posted.");

        return self::SUCCESS;
    }
}
