<?php

namespace App\Console\Commands\ContentStudio;

use App\Console\Commands\ContentStudio\Concerns\FindsContentItem;
use App\Services\ContentStudio\Review\ContentReview;
use App\Services\ContentStudio\Review\ReviewException;
use Illuminate\Console\Command;

class RetryContent extends Command
{
    use FindsContentItem;

    protected $signature = 'content:retry {--content-id= : Content item id}';

    protected $description = 'Run the pipeline again for an item that FAILED (blocked and review items need a decision, not a retry)';

    public function handle(ContentReview $review): int
    {
        if (($item = $this->contentItem()) === null) {
            return self::FAILURE;
        }

        try {
            $run = $review->retry($item, 'cli');
        } catch (ReviewException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Item {$item->id} is producing again (run {$run->id}).");

        return self::SUCCESS;
    }
}
