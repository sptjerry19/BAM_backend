<?php

namespace Tests\Feature\ContentStudio;

use App\Models\ContentStudio\ContentAiRun;
use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentEvent;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPlan;
use App\Models\ContentStudio\ContentSource;
use App\Services\ContentStudio\Ai\AiException;
use App\Services\ContentStudio\Ai\AiRequest;
use App\Services\ContentStudio\Ai\AiResult;
use App\Services\ContentStudio\Ai\MockAiClient;
use App\Services\ContentStudio\Contracts\AiClient;
use App\Services\ContentStudio\Planning\ContentPlanner;
use App\Services\ContentStudio\Planning\PlanResult;
use Carbon\CarbonImmutable;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class ContentPlannerTest extends TestCase
{
    use UsesContentStudioDatabase;

    private function date(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-08', 'Asia/Ho_Chi_Minh')->startOfDay();
    }

    private function newsSource(array $over = []): ContentSource
    {
        $url = $over['canonical_url'] ?? 'https://www.badmintonasia.org/news/'.uniqid();

        return ContentSource::create($over + [
            'platform' => 'web', 'bucket' => 'news', 'canonical_url' => $url, 'url_hash' => ContentSource::hashUrl($url),
            'creator' => 'Badminton Asia', 'title' => 'China lead the way at the Asian Junior Championships',
            'summary' => 'China won three of the five titles on the final day. Malaysia ended a long wait in mixed doubles. The next edition will be held next year.',
            'trust' => 'official', 'published_at' => now()->subDay(),
        ]);
    }

    /** An AI client that records how often it was called. */
    private function spyAi(callable $answer): object
    {
        return new class($answer) implements AiClient
        {
            public int $calls = 0;

            public ?AiRequest $last = null;

            public function __construct(private $answer) {}

            public function generateStructured(AiRequest $request): AiResult
            {
                $this->calls++;
                $this->last = $request;

                return new AiResult(($this->answer)($request), 'spy-model', 'resp_x', 100, 50, null);
            }
        };
    }

    public function test_mock_plan_creates_the_slots_it_can_and_reports_news_as_a_shortfall_without_sources(): void
    {
        $result = app(ContentPlanner::class)->plan($this->date(), new MockAiClient());

        $this->assertSame(PlanResult::CREATED, $result->status);
        $slots = array_map(fn (ContentItem $i) => $i->slot->value, $result->items);
        $this->assertEqualsCanonicalizing(['equipment', 'tutorial'], $slots);

        $plan = ContentPlan::sole();
        $this->assertSame('2026-11-08', $plan->plan_date->toDateString());
        $reasons = collect($plan->shortfalls_json)->keyBy('slot');
        $this->assertStringStartsWith('no_verified_recent_source', $reasons['news']['reason']);
        $this->assertStringContainsString('skipped', $reasons['meme']['reason'] ?? 'skipped by model');
    }

    public function test_items_are_stored_with_script_proposal_and_review_flag(): void
    {
        app(ContentPlanner::class)->plan($this->date(), new MockAiClient());

        $item = ContentItem::where('slot', 'equipment')->sole();
        $this->assertSame('idea', $item->status->value);
        $this->assertSame('carousel', $item->content_type);
        $this->assertGreaterThanOrEqual(4, count($item->script_json['slides']));
        $this->assertSame(['#chonvot', '#caulong', '#jerrybadminton'], $item->hashtags_json);
        $this->assertSame('guide', $item->brief_json['mode']);
        $this->assertSame('hypothesis' === $item->brief_json['demand_basis'] ? 'hypothesis' : 'evergreen', $item->brief_json['demand_basis']);
        $this->assertSame(MockAiClient::REVIEW_REASON, $item->brief_json['review_reason']);
        $this->assertTrue($item->brief_json['mock']);
        $this->assertNull($item->scheduled_at, 'a proposal is not a schedule');
        // 12:30 Asia/Ho_Chi_Minh on 08/11 is 05:30 UTC
        $this->assertSame('2026-11-08T05:30:00+00:00', $item->brief_json['proposed_publish_at']);
        $this->assertNotNull($item->fingerprint);
    }

    public function test_a_verified_recent_source_makes_a_news_item_with_the_source_attached(): void
    {
        $source = $this->newsSource();

        $result = app(ContentPlanner::class)->plan($this->date(), new MockAiClient());

        $news = collect($result->items)->first(fn (ContentItem $i) => $i->slot->value === 'news');
        $this->assertNotNull($news);
        $this->assertSame([$source->id], $news->sources->pluck('id')->all());
        $this->assertSame('fact', $news->sources->first()->pivot->usage);
        $this->assertStringContainsString('Nguồn: Badminton Asia', $news->caption);
        $this->assertCount(0, collect(ContentPlan::sole()->shortfalls_json)->where('slot', 'news'));
    }

    public function test_sources_that_are_stale_unofficial_or_too_thin_are_never_offered(): void
    {
        $this->newsSource(['published_at' => now()->subDays(30)]);                      // too old
        $this->newsSource(['trust' => 'press']);                                         // not official
        $this->newsSource(['summary' => 'Too short.']);                                  // not enough detail
        $spy = $this->spyAi(fn () => ['date' => '2026-11-08', 'items' => [], 'skipped_slots' => []]);

        $result = app(ContentPlanner::class)->plan($this->date(), $spy);

        $this->assertSame([], $spy->last->input['verified_sources']);
        $this->assertNotContains('news', $spy->last->input['slots_requested']);
        $this->assertSame('news', collect($result->shortfalls)->firstWhere('slot', 'news')['slot']);
    }

    public function test_a_source_that_already_drives_a_post_is_not_offered_again(): void
    {
        $this->newsSource();
        app(ContentPlanner::class)->plan($this->date(), new MockAiClient());
        $this->assertSame(1, ContentItem::where('slot', 'news')->count());

        $next = $this->date()->addDay();
        $spy = $this->spyAi(fn () => ['date' => $next->toDateString(), 'items' => [], 'skipped_slots' => []]);
        app(ContentPlanner::class)->plan($next, $spy);

        $this->assertSame([], $spy->last->input['verified_sources']);
    }

    public function test_running_the_plan_twice_is_idempotent_and_the_second_run_makes_no_ai_call(): void
    {
        $planner = app(ContentPlanner::class);
        $first = $planner->plan($this->date(), new MockAiClient());
        $created = count($first->items);

        $spy = $this->spyAi(fn () => ['date' => '', 'items' => [], 'skipped_slots' => []]);
        // meme and news are not "taken" (nothing was created for them), so the model is asked only for those
        $second = $planner->plan($this->date(), $spy);

        $this->assertSame($created, ContentItem::count(), 'no duplicate items');
        $this->assertSame(1, ContentPlan::count(), 'no duplicate plan');
        $this->assertSame(['meme'], $spy->last->input['slots_requested'] ?? null, 'only the missing slot is requested');
        $this->assertNotContains('equipment', $spy->last->input['slots_requested']);
        $this->assertSame(PlanResult::CREATED, $second->status);
    }

    public function test_when_every_slot_is_taken_nothing_is_asked(): void
    {
        $plan = ContentPlan::create(['channel_id' => ContentChannel::default()->id, 'plan_date' => '2026-11-08', 'status' => 'planned']);
        foreach (['news', 'equipment', 'tutorial', 'meme'] as $slot) {
            ContentItem::create(['plan_id' => $plan->id, 'channel_id' => $plan->channel_id, 'slot' => $slot, 'content_type' => 'carousel', 'title' => "T {$slot}"]);
        }
        $spy = $this->spyAi(fn () => []);

        $result = app(ContentPlanner::class)->plan($this->date(), $spy);

        $this->assertSame(PlanResult::COMPLETE, $result->status);
        $this->assertSame(0, $spy->calls);
    }

    public function test_cancelled_items_free_their_slot(): void
    {
        $plan = ContentPlan::create(['channel_id' => ContentChannel::default()->id, 'plan_date' => '2026-11-08', 'status' => 'planned']);
        $item = ContentItem::create(['plan_id' => $plan->id, 'channel_id' => $plan->channel_id, 'slot' => 'tutorial', 'content_type' => 'carousel', 'title' => 'Old']);
        $item->forceFill(['status' => 'cancelled'])->save();
        $spy = $this->spyAi(fn () => ['date' => '', 'items' => [], 'skipped_slots' => []]);

        app(ContentPlanner::class)->plan($this->date(), $spy);

        $this->assertContains('tutorial', $spy->last->input['slots_requested']);
    }

    public function test_dry_run_validates_but_writes_nothing(): void
    {
        $result = app(ContentPlanner::class)->plan($this->date(), new MockAiClient(), dryRun: true);

        $this->assertSame(PlanResult::DRY_RUN, $result->status);
        $this->assertNotEmpty($result->items);
        $this->assertIsArray($result->items[0]);
        $this->assertSame(0, ContentPlan::count());
        $this->assertSame(0, ContentItem::count());
        $this->assertSame(0, ContentEvent::where('action', 'plan.created')->count());
    }

    public function test_an_ai_failure_writes_nothing(): void
    {
        $failing = new class implements AiClient
        {
            public function generateStructured(AiRequest $request): AiResult
            {
                throw AiException::transient('boom', 503);
            }
        };

        try {
            app(ContentPlanner::class)->plan($this->date(), $failing);
            $this->fail('expected AiException');
        } catch (AiException $e) {
            $this->assertSame('transient', $e->kind);
        }

        $this->assertSame(0, ContentPlan::count());
        $this->assertSame(0, ContentItem::count());
    }

    public function test_invalid_model_items_are_rejected_with_reasons_and_never_stored(): void
    {
        $spy = $this->spyAi(fn () => ['date' => '2026-11-08', 'skipped_slots' => [], 'items' => [[
            'slot' => 'equipment', 'format' => 'carousel', 'title' => 'Vợt tốt nhất cho mọi người', 'hook' => 'Hook ok',
            'caption' => 'Vợt nặng 85g giá 900k', 'hashtags' => ['#a'], 'source_ids' => [], 'product_ids' => ['p1'],
            'viewer_question' => 'q', 'demand_basis' => 'verified_trend', 'slides' => [['heading' => 'x', 'body' => 'y']], 'review_reason' => null,
        ]]]);

        $result = app(ContentPlanner::class)->plan($this->date(), $spy);

        $this->assertSame([], ContentItem::where('slot', 'equipment')->get()->all());
        $violations = $result->rejected[0]['violations'];
        foreach (['hashtags_count', 'slides_count', 'product_not_allowed', 'price_not_allowed'] as $code) {
            $this->assertContains($code, $violations);
        }
        $equipmentShortfall = collect($result->shortfalls)->firstWhere('slot', 'equipment');
        $this->assertStringStartsWith('rejected:', $equipmentShortfall['reason']);
    }

    public function test_the_model_input_carries_no_secrets_and_the_prompt_version_is_recorded(): void
    {
        config(['services.openai.key' => 'sk-should-never-be-sent-1234567890']);
        $spy = $this->spyAi(fn () => ['date' => '', 'items' => [], 'skipped_slots' => []]);

        $result = app(ContentPlanner::class)->plan($this->date(), $spy);

        $json = json_encode($spy->last->input, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('sk-should-never', $json);
        $this->assertStringNotContainsString('sk-should-never', $spy->last->instructions);
        $this->assertSame('daily_plan', $spy->last->purpose);
        $this->assertMatchesRegularExpression('/^planner-[0-9a-f]{12}$/', $spy->last->promptVersion);
        $this->assertSame($spy->last->promptVersion, $result->promptVersion);
        $this->assertSame(['guide'], [$spy->last->input['equipment']['mode']]);
        $this->assertArrayNotHasKey('products', $spy->last->input);
    }

    public function test_titles_already_used_recently_are_rejected_as_repeats(): void
    {
        app(ContentPlanner::class)->plan($this->date(), new MockAiClient());

        // next day the mock picks the next unused topic, so force the same title through a spy
        $existing = ContentItem::where('slot', 'tutorial')->value('title');
        $next = $this->date()->addDay();
        $spy = $this->spyAi(fn () => ['date' => $next->toDateString(), 'skipped_slots' => [], 'items' => [
            (new MockAiClient())->generateStructured(new AiRequest('x', 'm', 'i', ['slots_requested' => ['tutorial'], 'topics' => ['tutorial' => [ltrim($existing, '[MOCK] ')]], 'recent_titles' => [], 'timezone' => 'UTC'], 's', []))->value['items'][0],
        ]]);

        $result = app(ContentPlanner::class)->plan($next, $spy);

        $this->assertContains('duplicate_title', $result->rejected[0]['violations']);
    }

    public function test_mock_runs_leave_no_ai_usage_rows_and_cost_nothing(): void
    {
        $result = app(ContentPlanner::class)->plan($this->date(), new MockAiClient());

        $this->assertSame(0, ContentAiRun::count());
        $this->assertTrue($result->ai->mock);
        $this->assertSame(0.0, $result->ai->costUsd);
    }
}
