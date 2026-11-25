<?php

namespace App\Services\ContentStudio\Pipeline;

use App\Enums\ContentStudio\RunStatus;
use App\Jobs\ContentStudio\ProcessContentItem;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPipelineRun;

final class ContentPipelineStarter
{
    public const PIPELINE = 'carousel';

    public function __construct(private readonly StepDispatcher $dispatcher) {}

    /** Start the carousel pipeline for an item. An item with an unfinished run never gets a second one. */
    public function start(ContentItem $item): ContentPipelineRun
    {
        $existing = ContentPipelineRun::query()
            ->where('content_item_id', $item->id)
            ->where('pipeline', self::PIPELINE)
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->latest('created_at')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $run = ContentPipelineRun::start($item, self::PIPELINE);
        $this->dispatcher->dispatch($run, ProcessContentItem::class, [$run->id]);

        return $run;
    }
}
