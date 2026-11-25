<?php

namespace App\Console\Commands\ContentStudio;

use App\Console\Commands\ContentStudio\Concerns\FindsContentItem;
use App\Enums\ContentStudio\ContentStatus;
use App\Services\ContentStudio\Pipeline\ContentPipelineStarter;
use App\Services\ContentStudio\State\ContentStateMachine;
use Illuminate\Console\Command;

class RetryContent extends Command
{
    use FindsContentItem;

    protected $signature = 'content:retry {--content-id= : Content item id}';

    protected $description = 'Run the pipeline again for an item that FAILED (blocked and review items need a decision, not a retry)';

    public function handle(ContentStateMachine $states, ContentPipelineStarter $starter): int
    {
        if (($item = $this->contentItem()) === null) {
            return self::FAILURE;
        }
        if ($item->status !== ContentStatus::Failed) {
            $this->error("Only a failed item can be retried (item {$item->id} is {$item->status->value}).");

            return self::FAILURE;
        }

        $item = $states->transition($item, ContentStatus::Producing, null, 'cli');
        $run = $starter->start($item);
        $this->info("Item {$item->id} is producing again (run {$run->id}).");

        return self::SUCCESS;
    }
}
