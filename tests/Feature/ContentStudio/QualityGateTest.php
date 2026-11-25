<?php

namespace Tests\Feature\ContentStudio;

use App\Models\ContentStudio\ContentAsset;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentItemProduct;
use App\Services\ContentStudio\Quality\QualityGate;
use App\Services\ContentStudio\Quality\QualityReport;
use App\Services\ContentStudio\Render\CarouselProducer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesContentItems;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class QualityGateTest extends TestCase
{
    use MakesContentItems;
    use UsesContentStudioDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeStorage();
    }

    /** An item that has been rendered, so file checks have something to look at. */
    private function rendered(?ContentItem $item = null): ContentItem
    {
        $item ??= $this->cleanItem();
        app(CarouselProducer::class)->produce($item);

        return $item->fresh();
    }

    /** @return array{0: string, 1: list<string>} verdict and failed codes */
    private function check(ContentItem $item): array
    {
        $report = app(QualityGate::class)->evaluate($item->fresh());

        return [$report->verdict(), array_column($report->failed(), 'code')];
    }

    public function test_clean_rendered_content_passes_every_check(): void
    {
        [$verdict, $failed] = $this->check($this->rendered());

        $this->assertSame([QualityReport::READY, []], [$verdict, $failed]);
    }

    public function test_unsupported_claims_go_to_a_reviewer_not_to_the_bin(): void
    {
        $item = $this->rendered($this->cleanItem('tutorial', ['hook' => 'Mình đã test và đây là vợt tốt nhất']));

        [$verdict, $failed] = $this->check($item);

        $this->assertSame(QualityReport::NEEDS_REVIEW, $verdict);
        $this->assertSame(['claims'], $failed);
    }

    public function test_news_only_wording_outside_news_is_flagged(): void
    {
        [, $failed] = $this->check($this->rendered($this->cleanItem('tutorial', ['hook' => 'Breaking: có mẹo mới'])));

        $this->assertContains('news_phrases', $failed);
    }

    public function test_hashtag_caption_and_slide_limits_are_enforced_on_the_final_text(): void
    {
        $this->assertContains('hashtags', $this->check($this->rendered($this->cleanItem('tutorial', ['hashtags_json' => ['#a', '#b']])))[1]);
        $this->assertContains('hashtags', $this->check($this->rendered($this->cleanItem('tutorial', ['hashtags_json' => ['#ok', '#bad-tag', '#fine']])))[1]);
        $this->assertContains('caption_length', $this->check($this->rendered($this->cleanItem('tutorial', ['caption' => str_repeat('a', 2300)])))[1]);
        $this->assertContains('fields', $this->check($this->rendered($this->cleanItem('tutorial', ['hook' => ''])))[1]);

        $long = $this->cleanItem('tutorial', ['script_json' => ['slides' => array_merge($this->cleanSlides(4), [['heading' => 'ok', 'body' => str_repeat('x', 300)]])]]);
        $this->assertContains('slides', $this->check($long)[1], 'a slide body over the limit');
    }

    public function test_a_required_disclosure_is_appended_by_construction_and_counts_towards_the_limit(): void
    {
        config(['content-studio.editorial.disclosure_required' => true, 'content-studio.editorial.disclosure_text' => '#quangcao']);
        $item = $this->cleanItem();

        $this->assertStringEndsWith('#quangcao', app(QualityGate::class)->finalCaption($item));
        $this->assertSame([QualityReport::READY, []], $this->check($this->rendered($item)));

        // 2165 characters + blank line (2) + these hashtags (33) = exactly 2200; the disclosure tips it over
        $tight = $this->cleanItem('tutorial', ['caption' => str_repeat('a', 2165)]);
        $this->assertContains('caption_length', $this->check($this->rendered($tight))[1]);
        config(['content-studio.editorial.disclosure_required' => false]);
        $this->assertNotContains('caption_length', $this->check($tight)[1]);
    }

    public function test_a_news_post_needs_an_official_dated_source_and_a_credit_line(): void
    {
        [$verdict, $failed] = $this->check($this->rendered($this->cleanItem('news')));
        $this->assertSame(QualityReport::BLOCKED, $verdict);
        $this->assertContains('news_source', $failed);

        $source = $this->newsSource(['trust' => 'press']);
        $press = $this->cleanItem('news', ['caption' => 'Có tin mới.']);
        $press->sources()->attach($source->id, ['usage' => 'fact']);
        $this->assertContains('news_source', $this->check($this->rendered($press))[1], 'press is not official');

        $uncredited = $this->newsItem(null, ['caption' => 'Có tin mới nhưng không ghi nguồn.']);
        [$verdict, $failed] = $this->check($this->rendered($uncredited));
        $this->assertSame([QualityReport::NEEDS_REVIEW, ['news_credit']], [$verdict, $failed]);

        $this->assertSame([QualityReport::READY, []], $this->check($this->rendered($this->newsItem())));
    }

    public function test_media_from_a_source_without_publishing_rights_is_blocked(): void
    {
        $item = $this->cleanItem();
        $reference = $this->newsSource(['bucket' => 'reup', 'trust' => null]);   // rights_unverified by default
        $item->sources()->attach($reference->id, ['usage' => 'asset']);

        [$verdict, $failed] = $this->check($this->rendered($item));
        $this->assertSame(QualityReport::BLOCKED, $verdict);
        $this->assertContains('source_rights', $failed);

        // the same source used only as a FACT is not a rights problem
        $factOnly = $this->cleanItem();
        $factOnly->sources()->attach($this->newsSource(['bucket' => 'reup', 'trust' => null])->id, ['usage' => 'reference']);
        $this->assertNotContains('source_rights', $this->check($this->rendered($factOnly))[1]);

        // and once rights are cleared with evidence it passes
        $reference->forceFill(['rights_status' => 'licensed'])->save();
        $this->assertNotContains('source_rights', $this->check($item)[1]);
    }

    public function test_an_item_that_was_never_rendered_is_blocked(): void
    {
        [$verdict, $failed] = $this->check($this->cleanItem());

        $this->assertSame(QualityReport::BLOCKED, $verdict);
        $this->assertContains('render', $failed);
    }

    public function test_a_slide_changed_after_rendering_is_caught_by_its_checksum(): void
    {
        $item = $this->rendered();
        Storage::disk('local')->put("content-studio/items/{$item->id}/v1/02.png", 'not the image that was rendered');

        [$verdict, $failed] = $this->check($item);

        $this->assertSame(QualityReport::BLOCKED, $verdict);
        $this->assertContains('assets', $failed);
    }

    public function test_a_deleted_slide_means_the_render_is_not_trusted(): void
    {
        $item = $this->rendered();
        Storage::disk('local')->delete("content-studio/items/{$item->id}/v1/03.png");

        $this->assertContains('render', $this->check($item)[1]);
    }

    public function test_a_valid_png_of_the_wrong_size_is_blocked_even_with_a_matching_checksum(): void
    {
        $item = $this->rendered();
        $path = "content-studio/items/{$item->id}/v1/01.png";
        ob_start();
        imagepng(imagecreatetruecolor(500, 500));
        $small = (string) ob_get_clean();
        Storage::disk('local')->put($path, $small);
        $manifestPath = "content-studio/items/{$item->id}/v1/manifest.json";
        $manifest = json_decode(Storage::disk('local')->get($manifestPath), true);
        $manifest['slides'][0]['sha256'] = hash('sha256', $small);
        Storage::disk('local')->put($manifestPath, json_encode($manifest));

        [$verdict, $failed] = $this->check($item);

        $this->assertSame(QualityReport::BLOCKED, $verdict);
        $this->assertContains('assets', $failed);
    }

    public function test_slides_without_a_clear_licence_are_blocked(): void
    {
        $item = $this->rendered();
        ContentAsset::where('content_item_id', $item->id)->first()->update(['license_state' => null]);

        [$verdict, $failed] = $this->check($item);

        $this->assertSame(QualityReport::BLOCKED, $verdict);
        $this->assertContains('asset_rights', $failed);
    }

    public function test_a_title_too_similar_to_a_recent_post_needs_review(): void
    {
        $first = $this->cleanItem('tutorial', ['title' => 'Bước chân về giữa sân cho người mới bắt đầu']);
        $second = $this->rendered($this->cleanItem('tutorial', ['title' => 'Bước chân về giữa sân cho người mới']));

        $this->assertContains('duplicate', $this->check($second)[1]);
        $this->assertNotContains('duplicate', $this->check($this->rendered($this->cleanItem('tutorial', ['title' => 'Chủ đề hoàn toàn khác biệt về phát cầu'])))[1]);
        $this->assertNotNull($first->id);
    }

    public function test_the_planners_own_doubt_is_surfaced_to_the_reviewer(): void
    {
        $item = $this->cleanItem('tutorial');
        $item->update(['brief_json' => array_merge($item->brief_json, ['review_reason' => 'Không chắc về một chi tiết kỹ thuật'])]);

        $report = app(QualityGate::class)->evaluate($this->rendered($item->fresh()));

        $this->assertSame(QualityReport::NEEDS_REVIEW, $report->verdict());
        $this->assertStringContainsString('Không chắc về một chi tiết', $report->summary());
    }

    public function test_equipment_text_may_not_invent_measurements_or_prices(): void
    {
        $guide = $this->cleanItem('equipment', ['caption' => 'Vợt nặng 85g, giá 900k, rất hợp người mới.']);

        [$verdict, $failed] = $this->check($this->rendered($guide));

        $this->assertSame(QualityReport::NEEDS_REVIEW, $verdict);
        $this->assertEqualsCanonicalizing(['equipment_specs', 'equipment_price'], $failed);

        $clean = $this->cleanItem('equipment');
        $this->assertSame([QualityReport::READY, []], $this->check($this->rendered($clean)));
    }

    public function test_a_price_is_allowed_only_while_its_snapshot_is_fresh(): void
    {
        $make = function (string $syncedAt): ContentItem {
            $item = $this->cleanItem('equipment', ['caption' => 'Vợt Pro nặng 85g, giá 2.000.000đ.']);
            ContentItemProduct::create([
                'content_item_id' => $item->id, 'product_id' => 7, 'role' => 'subject',
                'facts_snapshot_json' => ['facts' => ['weight' => '85g']],
                'price_snapshot_json' => ['visible' => true, 'value_vnd' => 2_000_000, 'synced_at' => $syncedAt],
            ]);

            return $this->rendered($item);
        };

        $this->assertSame([QualityReport::READY, []], $this->check($make(now()->subDay()->toIso8601String())));

        // the plan was made while the price was fresh, but it went stale before publishing: judged NOW
        $this->assertContains('equipment_price', $this->check($make(now()->subDays(30)->toIso8601String()))[1]);
    }

    public function test_the_report_serialises_for_qa_json(): void
    {
        $item = $this->rendered($this->cleanItem('tutorial', ['hook' => 'Vợt tốt nhất']));

        $qa = app(QualityGate::class)->evaluate($item)->toArray();

        $this->assertSame('needs_review', $qa['verdict']);
        $this->assertSame(['claims'], $qa['failed']);
        $this->assertNotEmpty($qa['checks']);
        $this->assertArrayHasKey('checked_at', $qa);
        foreach ($qa['checks'] as $check) {
            $this->assertSame(['code', 'passed', 'severity', 'message'], array_keys($check));
        }
    }
}
