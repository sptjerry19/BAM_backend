<?php

namespace Tests\Unit\ContentStudio;

use App\Services\ContentStudio\Planning\PlanContext;
use App\Services\ContentStudio\Planning\PlanValidator;
use App\Services\ContentStudio\Planning\TitleSimilarity;
use App\Services\ContentStudio\Quality\TextRules;
use Tests\TestCase;

class PlanValidatorTest extends TestCase
{
    private function validator(): PlanValidator
    {
        return new PlanValidator(new TextRules(), new TitleSimilarity());
    }

    private function source(string $id = 's1', int $hoursAgo = 3, string $publisher = 'Badminton Asia'): array
    {
        return [
            'id' => $id, 'source_id' => (int) substr($id, 1), 'publisher' => $publisher, 'title' => 'Title', 'summary' => str_repeat('x', 100),
            'published_at' => now()->subHours($hoursAgo)->toIso8601String(), 'published_ts' => now()->subHours($hoursAgo)->getTimestamp(), 'url' => 'https://e.test/a',
        ];
    }

    private function ctx(array $slots = ['news', 'equipment', 'tutorial', 'meme'], array $sources = [], array $products = [], string $mode = 'guide', array $recent = []): PlanContext
    {
        return new PlanContext('2026-11-08', $slots, [], $sources, $products, $mode, $recent);
    }

    private function slides(int $n = 5): array
    {
        return array_map(fn ($i) => ['heading' => "Ý {$i}", 'body' => "Nội dung ngắn gọn phần {$i} cho người chơi phong trào."], range(1, $n));
    }

    private function item(array $over = []): array
    {
        return $over + [
            'slot' => 'tutorial', 'format' => 'carousel', 'title' => 'Bước chân về giữa sân cho người mới', 'hook' => 'Về giữa sân đúng cách',
            'caption' => 'Một mẹo nhỏ giúp bạn di chuyển gọn hơn.', 'hashtags' => ['#caulong', '#footwork', '#jerrybadminton'],
            'source_ids' => [], 'product_ids' => [], 'viewer_question' => 'Làm sao di chuyển gọn hơn?', 'demand_basis' => 'evergreen',
            'slides' => $this->slides(), 'review_reason' => null,
        ];
    }

    private function validate(array $item, ?PlanContext $ctx = null): array
    {
        return $this->validator()->validate(['date' => '2026-11-08', 'items' => [$item], 'skipped_slots' => []], $ctx ?? $this->ctx());
    }

    private function codes(array $item, ?PlanContext $ctx = null): array
    {
        $r = $this->validate($item, $ctx);

        return $r['rejected'][0]['violations'] ?? [];
    }

    public function test_a_clean_item_is_accepted_and_normalised(): void
    {
        $r = $this->validate($this->item(['hashtags' => ['caulong', ' #foot work ', '#jerrybadminton'], 'caption' => 'Mẹo nhỏ #caulong cho bạn']));

        $this->assertSame([], $r['rejected']);
        $this->assertSame(['#caulong', '#footwork', '#jerrybadminton'], $r['items'][0]['hashtags']);
        $this->assertSame('Mẹo nhỏ cho bạn', $r['items'][0]['caption'], 'hashtags live in their own field');
    }

    public function test_slot_rules(): void
    {
        $this->assertContains('slot_not_requested', $this->codes($this->item(['slot' => 'news']), $this->ctx(['tutorial'])));
        $this->assertContains('slot_not_requested', $this->codes($this->item(['slot' => 'bogus'])));

        $r = $this->validator()->validate(['items' => [$this->item(), $this->item(['title' => 'Một tiêu đề hoàn toàn khác'])]], $this->ctx());
        $this->assertCount(1, $r['items']);
        $this->assertSame(['slot_duplicate'], $r['rejected'][0]['violations']);
    }

    public function test_length_and_count_limits(): void
    {
        $c = $this->codes($this->item([
            'title' => str_repeat('a', 101), 'hook' => str_repeat('b', 121), 'caption' => str_repeat('c', 2300),
            'hashtags' => ['#a', '#b'], 'slides' => $this->slides(3),
        ]));
        foreach (['title_too_long', 'hook_too_long', 'caption_too_long', 'hashtags_count', 'slides_count'] as $code) {
            $this->assertContains($code, $c);
        }
        $this->assertContains('slides_count', $this->codes($this->item(['slides' => $this->slides(7)])));
        $this->assertContains('slide_too_long', $this->codes($this->item(['slides' => array_merge($this->slides(4), [['heading' => 'ok', 'body' => str_repeat('x', 221)]])])));
        $this->assertContains('slide_empty', $this->codes($this->item(['slides' => array_merge($this->slides(4), [['heading' => '', 'body' => 'x']])])));
        $this->assertContains('title_empty', $this->codes($this->item(['title' => ' '])));
    }

    public function test_hashtag_rules(): void
    {
        $this->assertContains('hashtag_duplicate', $this->codes($this->item(['hashtags' => ['#Caulong', '#caulong', '#x']])));
        $this->assertContains('hashtag_format', $this->codes($this->item(['hashtags' => ['#ok', '#bad-tag', '#fine']])));
        $this->assertContains('hashtag_format', $this->codes($this->item(['hashtags' => ['#ok', '#'.str_repeat('a', 40), '#fine']])));
    }

    public function test_disclosure_counts_towards_the_caption_limit(): void
    {
        config(['content-studio.editorial.disclosure_required' => true, 'content-studio.editorial.disclosure_text' => '#quangcao']);
        // 2164 + blank line (2) + three hashtags (34) = exactly 2200; the disclosure adds 10 more.
        $caption = str_repeat('a', 2164);

        $this->assertContains('caption_too_long', $this->codes($this->item(['caption' => $caption])));
        config(['content-studio.editorial.disclosure_required' => false]);
        $this->assertNotContains('caption_too_long', $this->codes($this->item(['caption' => $caption])));
    }

    public function test_forbidden_claims_are_blocked_in_every_visible_field(): void
    {
        foreach ([
            ['title' => 'Vợt tốt nhất cho người mới'],
            ['hook' => 'Mình đã test cây vợt này'],
            ['caption' => 'Cam kết tăng trình sau 1 tuần'],
            ['slides' => array_merge($this->slides(4), [['heading' => 'Hiệu quả 100%', 'body' => 'ok']])],
            ['caption' => 'Bài này giúp điều trị đau cổ tay'],
        ] as $over) {
            $this->assertNotEmpty(array_filter($this->codes($this->item($over)), fn ($c) => str_starts_with($c, 'forbidden_phrase:')), json_encode($over, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_forbidden_phrases_do_not_false_positive_on_normal_vietnamese(): void
    {
        $r = $this->validate($this->item(['caption' => 'Bạn chưa biết cách làm nóng người? Số 10 bài tập nhẹ giúp khởi động tốt hơn.']));

        $this->assertSame([], $r['rejected'], '"chưa" must not match "chữa", "số 10" must not match "số 1"');
    }

    public function test_news_only_phrases_need_a_fresh_verified_source(): void
    {
        $this->assertContains('news_only_phrase:breaking', $this->codes($this->item(['hook' => 'Breaking: có gì đó'])));

        $news = $this->item([
            'slot' => 'news', 'source_ids' => ['s1'], 'hook' => 'Breaking: kết quả chung kết', 'caption' => "Tóm tắt.\n\nNguồn: Badminton Asia",
        ]);
        $this->assertSame([], $this->validate($news, $this->ctx(sources: [$this->source('s1', hoursAgo: 3)]))['rejected'], 'a 3 hour old source may be called breaking');
        $this->assertContains('news_only_phrase:breaking', $this->codes($news, $this->ctx(sources: [$this->source('s1', hoursAgo: 72)])));
    }

    public function test_news_needs_known_sources_and_a_credit_line(): void
    {
        $ctx = $this->ctx(sources: [$this->source('s1')]);
        $good = $this->item(['slot' => 'news', 'source_ids' => ['s1'], 'caption' => "Tóm tắt.\n\nNguồn: Badminton Asia"]);

        $this->assertSame([], $this->validate($good, $ctx)['rejected']);
        $this->assertContains('source_missing', $this->codes($this->item(['slot' => 'news', 'source_ids' => []]), $ctx));
        $this->assertContains('source_unknown:s99', $this->codes($this->item(['slot' => 'news', 'source_ids' => ['s99'], 'caption' => 'Nguồn: Badminton Asia']), $ctx));
        $this->assertContains('news_missing_credit', $this->codes($this->item(['slot' => 'news', 'source_ids' => ['s1'], 'caption' => 'Không có dòng nguồn']), $ctx));
        $this->assertContains('news_missing_credit', $this->codes($this->item(['slot' => 'news', 'source_ids' => ['s1'], 'caption' => 'Nguồn: một trang khác']), $ctx), 'must name the real publisher');
    }

    public function test_guide_mode_forbids_products_numbers_with_units_and_prices(): void
    {
        $equipment = fn (array $over) => $this->item($over + ['slot' => 'equipment']);

        $this->assertSame([], $this->validate($equipment([]))['rejected']);
        $this->assertContains('product_not_allowed', $this->codes($equipment(['product_ids' => ['p1']])));
        $this->assertContains('spec_token_unverified:85g', $this->codes($equipment(['caption' => 'Vợt nặng 85g phù hợp người mới'])));
        $this->assertSame([], $this->validate($equipment(['hook' => 'Vợt 3U hay 4U?']))['rejected'], '3U / 4U are weight-class names, not measurements');
        $this->assertContains('spec_token_unverified:83g', $this->codes($equipment(['caption' => 'Hạng 4U thường khoảng 83 g'])));
        $this->assertContains('spec_token_unverified:290mm', $this->codes($equipment(['caption' => 'Điểm cân bằng 290 mm'])));
        $this->assertContains('price_not_allowed', $this->codes($equipment(['caption' => 'Chỉ từ 1.500.000đ'])));
        $this->assertContains('price_not_allowed', $this->codes($equipment(['caption' => 'Giá 900k thôi'])));
        $this->assertNotContains('price_not_allowed', $this->codes($equipment(['caption' => 'Bạn có 10 điểm để chơi'])), '"10 điểm" is not a price');
    }

    public function test_product_mode_allows_only_the_facts_that_were_offered(): void
    {
        $product = ['id' => 'p7', 'product_id' => 7, 'name' => 'Vợt A', 'brand' => 'X', 'facts' => ['weight' => '85g', 'balance' => '290 mm'], 'missing_specs' => ['flex'],
            'price' => ['visible' => true, 'value_vnd' => 1500000, 'synced_at' => now()->toIso8601String()], 'source_url' => null];
        $ctx = $this->ctx(['equipment'], products: [$product], mode: 'product');
        $base = ['slot' => 'equipment', 'product_ids' => ['p7']];

        $this->assertSame([], $this->validate($this->item($base + ['caption' => 'Vợt A nặng 85g, giá 1.500.000đ']), $ctx)['rejected']);
        $this->assertContains('spec_token_unverified:88g', $this->codes($this->item($base + ['caption' => 'Vợt A nặng 88g']), $ctx));
        $this->assertContains('product_unknown:p9', $this->codes($this->item(['slot' => 'equipment', 'product_ids' => ['p9']]), $ctx));
        $this->assertContains('product_missing', $this->codes($this->item(['slot' => 'equipment', 'product_ids' => []]), $ctx));

        $stale = $product;
        $stale['price'] = ['visible' => false, 'value_vnd' => null, 'synced_at' => now()->subDays(30)->toIso8601String()];
        $this->assertContains('price_not_allowed', $this->codes($this->item($base + ['caption' => 'Vợt A giá 1.500.000đ']), $this->ctx(['equipment'], products: [$stale], mode: 'product')));
    }

    public function test_repeats_are_rejected(): void
    {
        $recent = ['Bước chân về giữa sân cho người mới bắt đầu'];

        $this->assertContains('duplicate_title', $this->codes($this->item(), $this->ctx(recent: $recent)));

        $r = $this->validator()->validate(['items' => [
            $this->item(['slot' => 'tutorial']),
            $this->item(['slot' => 'meme']), // same title, other slot
        ]], $this->ctx());
        $this->assertSame(['duplicate_in_plan'], $r['rejected'][0]['violations']);
    }

    public function test_the_model_cannot_claim_a_verified_trend(): void
    {
        $r = $this->validate($this->item(['demand_basis' => 'verified_trend']));

        $this->assertSame('hypothesis', $r['items'][0]['demand_basis'], 'no trend evidence is ever offered, so it is downgraded');
    }

    public function test_skipped_slots_are_carried_through(): void
    {
        $r = $this->validator()->validate(['items' => [], 'skipped_slots' => [['slot' => 'meme', 'reason' => 'nothing good today']]], $this->ctx());

        $this->assertSame([['slot' => 'meme', 'reason' => 'nothing good today']], $r['skipped']);
    }
}
