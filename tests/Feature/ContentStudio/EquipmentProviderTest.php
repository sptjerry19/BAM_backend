<?php

namespace Tests\Feature\ContentStudio;

use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentItemProduct;
use App\Models\Product;
use App\Services\ContentStudio\Ai\AiRequest;
use App\Services\ContentStudio\Ai\AiResult;
use App\Services\ContentStudio\Contracts\AiClient;
use App\Services\ContentStudio\Planning\ContentPlanner;
use App\Services\ContentStudio\Sources\EquipmentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

/**
 * Fixtures mirror the real `products` table (checked 07/11/2026): a retailer
 * import whose descriptions are "Name\n\nNguồn: <url>" with almost no specs.
 */
class EquipmentProviderTest extends TestCase
{
    use UsesContentStudioDatabase;

    private function product(array $over = []): Product
    {
        $id = DB::table('products')->insertGetId($over + [
            'name' => 'Vợt cầu lông Demo', 'brand' => 'Demo', 'category' => 'racket', 'price' => 2_000_000, 'stock' => 5, 'sold_count' => 10,
            'description' => "Vợt cầu lông Demo\n\nNguồn: https://shopvnb.com/vot-demo.html",
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Product::findOrFail($id);
    }

    private const FULL_SPECS = "Vợt cầu lông Pro\n\nTrọng lượng: 85g\nĐộ cứng: Trung bình\nChiều dài: 675mm\nĐiểm cân bằng: 290mm\nChất liệu: Carbon\n\nNguồn: https://shopvnb.com/vot-pro.html";

    public function test_a_typical_imported_row_exposes_only_what_it_really_contains(): void
    {
        $facts = app(EquipmentProvider::class)->factsFor($this->product());

        $this->assertSame([], $facts['facts'], 'nothing is inferred from the name');
        $this->assertSame(['weight', 'length', 'balance', 'flex', 'material'], $facts['missing_specs']);
        $this->assertSame('https://shopvnb.com/vot-demo.html', $facts['source_url']);
        $this->assertTrue($facts['price']['visible']);
        $this->assertSame(2_000_000, $facts['price']['value_vnd']);
        $this->assertTrue($facts['in_stock']);
        $this->assertArrayNotHasKey('image', $facts, 'product photos have no established rights and are never exposed');
    }

    public function test_labelled_lines_become_facts(): void
    {
        $facts = app(EquipmentProvider::class)->factsFor($this->product(['description' => self::FULL_SPECS]));

        $this->assertSame(['weight' => '85g', 'flex' => 'Trung bình', 'length' => '675mm', 'balance' => '290mm', 'material' => 'Carbon'], $facts['facts']);
        $this->assertSame([], $facts['missing_specs']);
    }

    public function test_urls_and_oversized_values_are_not_facts(): void
    {
        $facts = app(EquipmentProvider::class)->factsFor($this->product(['description' => "Chất liệu: https://x.test/spec\nTrọng lượng: ".str_repeat('a', 90)."\nĐộ cứng: Cứng"]));

        $this->assertSame(['flex' => 'Cứng'], $facts['facts']);
    }

    public function test_a_stale_or_zero_price_is_hidden(): void
    {
        $provider = app(EquipmentProvider::class);

        $stale = $provider->factsFor($this->product(['updated_at' => now()->subDays(30)]));
        $this->assertFalse($stale['price']['visible']);
        $this->assertNull($stale['price']['value_vnd'], 'the number itself is withheld, not just flagged');

        $zero = $provider->factsFor($this->product(['price' => 0]));
        $this->assertFalse($zero['price']['visible']);

        $edge = $provider->factsFor($this->product(['updated_at' => now()->subDays(7)->addMinute()]));
        $this->assertTrue($edge['price']['visible'], 'inside the 7 day window');
    }

    public function test_only_rackets_with_enough_real_facts_become_product_mode_candidates(): void
    {
        $this->product(['name' => 'Chỉ có tên']);                                                       // no facts
        $this->product(['name' => 'Hết hàng', 'description' => self::FULL_SPECS, 'stock' => 0]);        // out of stock
        $this->product(['name' => 'Giá 0', 'description' => self::FULL_SPECS, 'price' => 0]);           // no price
        $this->product(['name' => 'Giày', 'category' => 'shoes', 'description' => self::FULL_SPECS]);   // not a racket
        $this->product(['name' => 'Đủ thông số', 'description' => self::FULL_SPECS]);
        $this->product(['name' => 'Hai thông số', 'description' => "Trọng lượng: 80g\nĐộ cứng: Mềm"]); // below the minimum of 3

        $candidates = app(EquipmentProvider::class)->productModeCandidates();

        $this->assertSame(['Đủ thông số'], array_column($candidates, 'name'));
    }

    public function test_with_the_current_data_shape_the_planner_stays_in_guide_mode(): void
    {
        foreach (range(1, 20) as $i) {
            $this->product(['name' => "Vợt {$i}", 'sold_count' => $i]);
        }
        $spy = $this->aiSpy(fn () => ['date' => '', 'items' => [], 'skipped_slots' => []]);

        app(ContentPlanner::class)->plan(CarbonImmutable::parse('2026-11-08', 'Asia/Ho_Chi_Minh'), $spy);

        $this->assertSame('guide', $spy->last->input['equipment']['mode']);
        $this->assertArrayNotHasKey('products', $spy->last->input, 'no product data goes to the model in guide mode');
        $this->assertNotEmpty($spy->last->input['equipment']['topics']);
    }

    public function test_product_mode_sends_facts_and_hides_stale_prices_then_stores_a_snapshot(): void
    {
        $fresh = $this->product(['name' => 'Vợt Pro Fresh', 'description' => self::FULL_SPECS]);
        $spy = $this->aiSpy(fn () => ['date' => '2026-11-08', 'skipped_slots' => [], 'items' => [[
            'slot' => 'equipment', 'format' => 'carousel', 'title' => 'Vợt Pro Fresh hợp với ai', 'hook' => 'Vợt Pro Fresh: ai nên chọn',
            'caption' => 'Vợt Pro Fresh nặng 85g, dài 675mm. Điểm cân bằng 290mm.', 'hashtags' => ['#chonvot', '#caulong', '#jerrybadminton'],
            'source_ids' => [], 'product_ids' => ['p'.$fresh->id], 'viewer_question' => 'Vợt này hợp với ai?', 'demand_basis' => 'hypothesis',
            'slides' => array_map(fn ($i) => ['heading' => "Ý {$i}", 'body' => "Phân tích dựa trên thông số có trong dữ liệu, phần {$i}."], range(1, 4)),
            'review_reason' => null,
        ]]]);

        $result = app(ContentPlanner::class)->plan(CarbonImmutable::parse('2026-11-08', 'Asia/Ho_Chi_Minh'), $spy);

        $this->assertSame('product', $spy->last->input['equipment']['mode']);
        $offered = $spy->last->input['products'][0];
        $this->assertSame(['id' => 'p'.$fresh->id, 'name' => 'Vợt Pro Fresh'], ['id' => $offered['id'], 'name' => $offered['name']]);
        $this->assertSame('85g', $offered['facts']['weight']);
        $this->assertTrue($offered['price']['visible']);

        $item = ContentItem::where('slot', 'equipment')->sole();
        $this->assertSame('product', $item->brief_json['mode']);
        $snapshot = ContentItemProduct::sole();
        $this->assertSame($fresh->id, $snapshot->product_id);
        $this->assertSame('85g', $snapshot->facts_snapshot_json['facts']['weight']);
        $this->assertSame(2_000_000, $snapshot->price_snapshot_json['value_vnd']);
        $this->assertCount(1, $result->items);
    }

    private function aiSpy(callable $answer): object
    {
        return new class($answer) implements AiClient
        {
            public ?AiRequest $last = null;

            public function __construct(private $answer) {}

            public function generateStructured(AiRequest $request): AiResult
            {
                $this->last = $request;

                return new AiResult(($this->answer)($request), 'spy', null, 1, 1, null);
            }
        };
    }
}
