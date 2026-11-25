<?php

namespace App\Services\ContentStudio\Planning;

use App\Models\ContentStudio\ContentPlan;
use App\Services\ContentStudio\Ai\AiResult;

final class PlanResult
{
    public const CREATED = 'created';     // plan written (items may be fewer than asked)
    public const DRY_RUN = 'dry_run';     // validated, nothing written
    public const COMPLETE = 'complete';   // every slot already has an item: no AI call made
    public const NO_SLOTS = 'no_slots';   // nothing the model can be asked for: no AI call made

    /**
     * @param  list<\App\Models\ContentStudio\ContentItem|array<string, mixed>>  $items  saved models, or proposed arrays on a dry run
     * @param  list<array<string, mixed>>  $rejected
     * @param  list<array{slot: string, reason: string}>  $shortfalls
     */
    public function __construct(
        public readonly string $status,
        public readonly string $date,
        public readonly ?ContentPlan $plan,
        public readonly array $items,
        public readonly array $rejected,
        public readonly array $shortfalls,
        public readonly ?AiResult $ai,
        public readonly ?string $promptVersion,
    ) {}
}
