<?php

namespace App\Services\ContentStudio\Planning;

use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentItemProduct;
use App\Models\ContentStudio\ContentPlan;
use App\Models\ContentStudio\ContentSource;
use App\Services\ContentStudio\Ai\AiRequest;
use App\Services\ContentStudio\Ai\BudgetGuard;
use App\Services\ContentStudio\Ai\PromptRepository;
use App\Services\ContentStudio\Contracts\AiClient;
use App\Services\ContentStudio\Ops\ContentEventLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily plan: decides which slots are needed, asks the model only for those it
 * can do well, validates the answer, and stores plan + items in one
 * transaction. Running it twice for the same date never duplicates anything.
 */
final class ContentPlanner
{
    public function __construct(
        private readonly PlanContextBuilder $contexts,
        private readonly PromptRepository $prompts,
        private readonly PlanValidator $validator,
        private readonly BudgetGuard $budget,
        private readonly ContentEventLogger $events,
        private readonly TitleSimilarity $similarity,
    ) {}

    /**
     * @throws \App\Services\ContentStudio\Ai\AiException when the model call fails (nothing is written)
     */
    public function plan(CarbonImmutable $date, AiClient $ai, bool $dryRun = false): PlanResult
    {
        $channel = ContentChannel::default();
        $dateString = $date->toDateString();

        $wanted = array_values(array_filter(
            array_keys((array) config('content-studio.slots')),
            fn (string $slot) => (int) config("content-studio.slots.{$slot}.max") > 0
                && ! in_array($slot, $this->plannedSlots($channel, $dateString), true),
        ));
        if ($wanted === []) {
            return new PlanResult(PlanResult::COMPLETE, $dateString, $this->findPlan($channel, $dateString), [], [], [], null, null);
        }

        $ctx = $this->contexts->build($channel, $date, $wanted);
        if ($ctx->slotsRequested === []) {
            $plan = $dryRun ? null : $this->storeShortfallsOnly($channel, $dateString, $ctx->shortfalls, $wanted);

            return new PlanResult(PlanResult::NO_SLOTS, $dateString, $plan, [], [], $ctx->shortfalls, null, null);
        }

        $prompt = $this->prompts->planner($ctx->slotsRequested);
        $this->budget->startRun();
        $result = $ai->generateStructured(new AiRequest(
            purpose: 'daily_plan',
            model: (string) config('content-studio.ai.models.planner'),
            instructions: $prompt['instructions'],
            input: $ctx->toModelInput(),
            schemaName: 'daily_content_plan',
            schema: $prompt['schema'],
            promptVersion: $prompt['version'],
        ));

        $validated = $this->validator->validate($result->value, $ctx);
        $shortfalls = $this->shortfalls($ctx, $validated);

        if ($dryRun) {
            return new PlanResult(PlanResult::DRY_RUN, $dateString, null, $validated['items'], $validated['rejected'], $shortfalls, $result, $prompt['version']);
        }

        [$plan, $items, $dropped] = $this->persist($channel, $date, $ctx, $validated['items'], $shortfalls, $result, $prompt['version']);

        $this->events->log('plan.created', 'content_plan', $plan->id, [
            'date' => $dateString, 'items' => count($items), 'rejected' => count($validated['rejected']) + count($dropped),
            'prompt_version' => $prompt['version'], 'model' => $result->model, 'mock' => $result->mock,
            'tokens_in' => $result->tokensIn, 'tokens_out' => $result->tokensOut,
        ]);

        return new PlanResult(PlanResult::CREATED, $dateString, $plan, $items, array_merge($validated['rejected'], $dropped), $plan->shortfalls_json ?? [], $result, $prompt['version']);
    }

    /**
     * Slots that did not make it, each with the real reason.
     *
     * @param  array{items: list<array<string, mixed>>, rejected: list<array<string, mixed>>, skipped: list<array{slot: string, reason: string}>}  $validated
     * @return list<array{slot: string, reason: string}>
     */
    private function shortfalls(PlanContext $ctx, array $validated): array
    {
        $out = $ctx->shortfalls;
        $made = array_column($validated['items'], 'slot');
        $skipped = array_column($validated['skipped'], 'reason', 'slot');
        $rejected = [];
        foreach ($validated['rejected'] as $r) {
            $rejected[$r['slot']] = 'rejected: '.implode(', ', $r['violations']);
        }

        foreach ($ctx->slotsRequested as $slot) {
            if (in_array($slot, $made, true)) {
                continue;
            }
            $out[] = ['slot' => $slot, 'reason' => $rejected[$slot] ?? (isset($skipped[$slot]) ? 'skipped by model: '.$skipped[$slot] : 'not returned by the model')];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $proposed
     * @param  list<array{slot: string, reason: string}>  $shortfalls
     * @return array{0: ContentPlan, 1: list<ContentItem>, 2: list<array<string, mixed>>}
     */
    private function persist(ContentChannel $channel, CarbonImmutable $date, PlanContext $ctx, array $proposed, array $shortfalls, $result, string $promptVersion): array
    {
        return DB::transaction(function () use ($channel, $date, $ctx, $proposed, $shortfalls, $result, $promptVersion) {
            $dateString = $date->toDateString();
            $plan = $this->findOrCreatePlan($channel, $dateString);
            // Serialise two concurrent planners for the same date, then re-check the slots.
            ContentPlan::query()->whereKey($plan->id)->lockForUpdate()->first();
            $taken = $this->plannedSlots($channel, $dateString);

            $sourceIndex = $ctx->sourceIndex();
            $productIndex = $ctx->productIndex();
            $items = [];
            $dropped = [];

            foreach ($proposed as $p) {
                if (in_array($p['slot'], $taken, true)) {
                    $dropped[] = ['slot' => $p['slot'], 'title' => $p['title'], 'violations' => ['slot_already_planned']];

                    continue;
                }

                $sourcePks = array_map(fn ($id) => (int) $sourceIndex[$id]['source_id'], $p['source_ids']);
                $productPks = array_map(fn ($id) => (int) $productIndex[$id]['product_id'], $p['product_ids']);
                $fingerprint = $this->fingerprint($p, $sourcePks, $productPks);

                if (ContentItem::query()->where('channel_id', $channel->id)->where('fingerprint', $fingerprint)->exists()) {
                    $dropped[] = ['slot' => $p['slot'], 'title' => $p['title'], 'violations' => ['duplicate_fingerprint']];

                    continue;
                }

                $item = ContentItem::create([
                    'plan_id' => $plan->id,
                    'channel_id' => $channel->id,
                    'slot' => $p['slot'],
                    'content_type' => 'carousel',
                    'title' => $p['title'],
                    'hook' => $p['hook'],
                    'caption' => $p['caption'],
                    'hashtags_json' => $p['hashtags'],
                    'script_json' => ['slides' => $p['slides']],
                    'brief_json' => [
                        'mode' => $p['slot'] === 'equipment' ? $ctx->equipmentMode : null,
                        'viewer_question' => $p['viewer_question'],
                        'demand_basis' => $p['demand_basis'],
                        'review_reason' => $p['review_reason'],
                        'plan_date' => $dateString,
                        'proposed_publish_at' => $this->proposedPublishAt($date, $p['slot']),
                        'prompt_version' => $promptVersion,
                        'model' => $result->model,
                        'mock' => $result->mock,
                        'render_version' => 1,
                    ],
                    'fingerprint' => $fingerprint,
                ]);

                foreach ($sourcePks as $pk) {
                    $item->sources()->attach($pk, ['usage' => 'fact']);
                }
                foreach ($p['product_ids'] as $id) {
                    $row = $productIndex[$id];
                    ContentItemProduct::create([
                        'content_item_id' => $item->id,
                        'product_id' => $row['product_id'],
                        'role' => 'subject',
                        'facts_snapshot_json' => ['facts' => $row['facts'], 'source_url' => $row['source_url'] ?? null, 'spec_source_url' => $row['spec_source_url'] ?? null, 'name' => $row['name'], 'brand' => $row['brand']],
                        'price_snapshot_json' => $row['price'],
                    ]);
                }

                $taken[] = $p['slot'];
                $items[] = $item;
            }

            $created = array_map(fn (ContentItem $i) => $i->slot->value, $items);
            $kept = collect($plan->shortfalls_json ?? [])
                ->reject(fn ($s) => in_array($s['slot'], $created, true))
                ->keyBy('slot');
            foreach ($shortfalls as $s) {
                $kept[$s['slot']] = $s;
            }
            foreach ($dropped as $d) {
                if (! isset($kept[$d['slot']]) && ! in_array($d['slot'], $taken, true)) {
                    $kept[$d['slot']] = ['slot' => $d['slot'], 'reason' => 'rejected: '.implode(', ', $d['violations'])];
                }
            }

            $plan->forceFill([
                'status' => 'planned',
                'generated_at' => now(),
                'prompt_version' => $promptVersion,
                'shortfalls_json' => $kept->values()->all(),
            ])->save();

            return [$plan->refresh(), $items, $dropped];
        });
    }

    /** @param list<array{slot: string, reason: string}> $shortfalls @param list<string> $wanted */
    private function storeShortfallsOnly(ContentChannel $channel, string $date, array $shortfalls, array $wanted): ContentPlan
    {
        $plan = $this->findOrCreatePlan($channel, $date);
        $kept = collect($plan->shortfalls_json ?? [])->keyBy('slot');
        foreach ($shortfalls as $s) {
            $kept[$s['slot']] = $s;
        }
        $plan->forceFill(['shortfalls_json' => $kept->values()->all(), 'generated_at' => now()])->save();

        return $plan;
    }

    /** @return list<string> */
    private function plannedSlots(ContentChannel $channel, string $date): array
    {
        return ContentItem::query()
            ->where('channel_id', $channel->id)
            ->where('status', '!=', 'cancelled')
            ->whereHas('plan', fn ($q) => $q->whereDate('plan_date', $date))
            ->get()
            ->map(fn (ContentItem $i) => $i->slot->value)
            ->unique()
            ->values()
            ->all();
    }

    private function findOrCreatePlan(ContentChannel $channel, string $date): ContentPlan
    {
        return $this->findPlan($channel, $date)
            ?? ContentPlan::create(['channel_id' => $channel->id, 'plan_date' => $date, 'status' => 'planned']);
    }

    private function findPlan(ContentChannel $channel, string $date): ?ContentPlan
    {
        return ContentPlan::query()->where('channel_id', $channel->id)->whereDate('plan_date', $date)->first();
    }

    /** @param array<string, mixed> $p @param list<int> $sourcePks @param list<int> $productPks */
    private function fingerprint(array $p, array $sourcePks, array $productPks): string
    {
        sort($sourcePks);
        sort($productPks);

        $basis = match (true) {
            $p['slot'] === 'news' && $sourcePks !== [] => 'news|'.implode(',', $sourcePks),
            $productPks !== [] => 'equipment|'.implode(',', $productPks),
            default => $p['slot'].'|'.$this->similarity->normalize($p['title']),
        };

        return hash('sha256', $basis);
    }

    private function proposedPublishAt(CarbonImmutable $date, string $slot): ?string
    {
        $time = config("content-studio.slots.{$slot}.publish_at");
        if (! $time) {
            return null;
        }
        $tz = (string) config('content-studio.timezone');

        // A proposal only. Nothing is "scheduled" until a publisher confirms it.
        return CarbonImmutable::parse($date->toDateString().' '.$time, $tz)->utc()->toIso8601String();
    }
}
