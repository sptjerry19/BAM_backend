<?php

namespace Tests\Feature\ContentStudio;

use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentItemProduct;
use App\Models\ContentStudio\RacketSpec;
use App\Models\Product;
use App\Services\ContentStudio\Ai\AiRequest;
use App\Services\ContentStudio\Ai\AiResult;
use App\Services\ContentStudio\Contracts\AiClient;
use App\Services\ContentStudio\Planning\ContentPlanner;
use App\Services\ContentStudio\Sources\EquipmentProvider;
use App\Services\ContentStudio\Sources\Specs\SpecMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

/**
 * Names and spec tables mirror what was seen on 26/11/2026: ShopVNB listings and
 * product pages on yonex.com / victorsport.com.
 */
class SpecMatchingTest extends TestCase
{
    use UsesContentStudioDatabase;

    private const YONEX = [
        'Flex' => 'STIFF', 'Frame' => 'HM Graphite, Namd', 'Shaft' => 'HM Graphite', 'Length' => '10 mm longer',
        'Weight / Grip' => '4U (Avg.83g) G5, 6 / 3U (Avg.88g) G4, 5, 6', 'Color(s)' => 'White Tiger',
    ];

    private function product(string $name, array $over = []): Product
    {
        $id = DB::table('products')->insertGetId($over + [
            'name' => $name, 'brand' => 'Yonex', 'category' => 'racket', 'price' => 3_000_000, 'stock' => 5, 'sold_count' => 10,
            'description' => "{$name}\n\nNguồn: https://shopvnb.com/x.html", 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Product::findOrFail($id);
    }

    private function spec(string $model, array $table = self::YONEX, string $brand = 'yonex', ?string $url = null): RacketSpec
    {
        $url ??= "https://www.{$brand}.com/".md5($model.json_encode($table));

        return RacketSpec::create([
            'brand' => $brand, 'model_name' => $model, 'source_url' => $url, 'specs_json' => $table,
            'content_hash' => hash('sha256', json_encode($table)), 'fetched_at' => now(),
        ]);
    }

    public function test_a_shop_listing_gets_the_facts_of_the_page_with_the_same_name(): void
    {
        $spec = $this->spec('ASTROX 99 PRO');

        $facts = app(EquipmentProvider::class)->factsFor($this->product('Vợt cầu lông Yonex Astrox 99 Pro chính hãng'));

        $this->assertSame([
            'weight' => '4U (Avg.83g) G5, 6 / 3U (Avg.88g) G4, 5, 6',
            'flex' => 'STIFF',
            'material' => 'Khung: HM Graphite, Namd; Thân: HM Graphite',
        ], $facts['facts'], 'a relative "10 mm longer" is not a length');
        $this->assertSame($spec->source_url, $facts['spec_source_url']);
        $this->assertSame(['length', 'balance'], $facts['missing_specs']);
    }

    public function test_victor_labels_and_absolute_length_are_read(): void
    {
        $this->spec('THRUSTER F CLAW', [
            'Weight / Grip Size' => '4U/G5', 'Frame Material' => 'Graphite', 'Shaft Material' => 'Graphite + Resin',
            'Length' => '675 mm', 'Stiffness' => 'S○○○●○F',
        ], 'victor');

        $facts = app(EquipmentProvider::class)->factsFor($this->product('Vợt Cầu Lông Victor Thruster F Claw', ['brand' => 'Victor']));

        $this->assertSame(['weight', 'length', 'material'], array_keys($facts['facts']), 'the dot-graphic stiffness is not copied');
    }

    public function test_the_manufacturer_wins_over_the_shop_description(): void
    {
        $this->spec('ASTROX 99 PRO');

        $facts = app(EquipmentProvider::class)->factsFor($this->product('Vợt cầu lông Yonex Astrox 99 Pro', [
            'description' => "Trọng lượng: 99g\nĐiểm cân bằng: 300mm",
        ]));

        $this->assertStringStartsWith('4U', $facts['facts']['weight']);
        $this->assertSame('300mm', $facts['facts']['balance'], 'what only the shop states is kept');
    }

    /** @return array<string, array{0: string}> */
    public static function otherRackets(): array
    {
        return array_map(fn ($name) => [$name], [
            'series sibling' => 'Vợt cầu lông Yonex Astrox 99 Tour',
            'shorter name' => 'Vợt cầu lông Yonex Astrox 99 chính hãng',
            'dated version' => 'Vợt cầu lông Yonex Astrox 99 Pro 2025',
            'edition' => 'Vợt cầu lông Yonex Astrox 99 Pro China Limited',
            'colour' => 'Vợt cầu lông Yonex Astrox 99 Pro - Đỏ chính hãng',
            'set' => 'SET Vợt Cầu Lông Yonex Astrox 99 Pro',
            'combo' => 'Combo mua vợt cầu lông Yonex Astrox 99 Pro tặng vợt Yonex Astrox 99 Pro',
        ]);
    }

    #[DataProvider('otherRackets')]
    public function test_anything_extra_in_the_name_means_no_match(string $name): void
    {
        $this->spec('ASTROX 99 PRO');

        $this->assertNull((new SpecMatcher)->match($this->product($name)));
    }

    public function test_two_pages_with_one_name_are_ambiguous(): void
    {
        $this->spec('ASTROX 99 PRO', url: 'https://www.yonex.com/astrox-99-pro-ax99-p');
        $this->spec('ASTROX 99 PRO', ['Flex' => 'STIFF'] + self::YONEX, url: 'https://www.yonex.com/astrox-99-pro-3ax99-p');

        $this->assertNull((new SpecMatcher)->match($this->product('Vợt cầu lông Yonex Astrox 99 Pro')));
    }

    /** @return array<string, array{0: string}> */
    public static function laterGenerations(): array
    {
        return array_map(fn ($name) => [$name], [
            'year' => 'Vợt cầu lông Yonex Astrox 99 Pro 2025',
            'year in brackets' => 'Vợt cầu lông Yonex Astrox 99 Pro (năm 2020) chính hãng',
            'new' => 'Vợt cầu lông Yonex Astrox 99 Pro new chính hãng',
        ]);
    }

    #[DataProvider('laterGenerations')]
    public function test_a_dated_sibling_in_the_shop_makes_the_bare_name_ambiguous(string $sibling): void
    {
        $this->spec('ASTROX 99 PRO');
        $bare = $this->product('Vợt cầu lông Yonex Astrox 99 Pro');
        $this->product($sibling, ['stock' => 0]); // even when it is sold out

        $this->assertNull((new SpecMatcher)->match($bare));
    }

    public function test_a_dated_sibling_of_a_longer_name_does_not_block_the_shorter_one(): void
    {
        $this->spec('ASTROX 99');
        $bare = $this->product('Vợt cầu lông Yonex Astrox 99');
        $this->product('Vợt cầu lông Yonex Astrox 99 Pro 2025');

        $this->assertNotNull((new SpecMatcher)->match($bare));
    }

    public function test_brand_and_category_must_agree(): void
    {
        $this->spec('ASTROX 99 PRO');
        $matcher = new SpecMatcher;

        $this->assertNull($matcher->match($this->product('Vợt cầu lông Yonex Astrox 99 Pro', ['brand' => 'Victor'])));
        $this->assertNull($matcher->match($this->product('Astrox 99 Pro', ['category' => 'bag'])));
    }

    public function test_imported_specs_switch_the_planner_to_product_mode_and_are_recorded(): void
    {
        $spec = $this->spec('ASTROX 99 PRO');
        $racket = $this->product('Vợt cầu lông Yonex Astrox 99 Pro');
        $this->product('Vợt cầu lông Yonex Astrox 99 Pro', ['sold_count' => 1]); // duplicate listing
        $this->product('Vợt cầu lông Yonex Astrox 99 Tour');                    // no page for it
        $spy = $this->aiSpy($racket->id);

        $result = app(ContentPlanner::class)->plan(CarbonImmutable::parse('2026-11-27', 'Asia/Ho_Chi_Minh'), $spy);

        $this->assertSame('product', $spy->last->input['equipment']['mode']);
        $this->assertSame(['p'.$racket->id], array_column($spy->last->input['products'], 'id'));
        $this->assertSame([], $result->rejected, 'a measurement taken from the spec page is allowed in the text');
        $this->assertSame($spec->source_url, ContentItemProduct::sole()->facts_snapshot_json['spec_source_url']);
        $this->assertSame('product', ContentItem::where('slot', 'equipment')->sole()->brief_json['mode']);
    }

    public function test_a_racket_that_already_has_a_post_is_not_offered_again(): void
    {
        $this->spec('ASTROX 99 PRO');
        $racket = $this->product('Vợt cầu lông Yonex Astrox 99 Pro');
        $planner = app(ContentPlanner::class);
        $planner->plan(CarbonImmutable::parse('2026-11-27', 'Asia/Ho_Chi_Minh'), $this->aiSpy($racket->id));

        $spy = $this->aiSpy($racket->id);
        $planner->plan(CarbonImmutable::parse('2026-11-28', 'Asia/Ho_Chi_Minh'), $spy);

        $this->assertSame('guide', $spy->last->input['equipment']['mode']);
    }

    private function aiSpy(int $productId): object
    {
        return new class($productId) implements AiClient
        {
            public ?AiRequest $last = null;

            public function __construct(private int $productId) {}

            public function generateStructured(AiRequest $request): AiResult
            {
                $this->last = $request;

                return new AiResult(['date' => '', 'skipped_slots' => [], 'items' => [[
                    'slot' => 'equipment', 'format' => 'carousel', 'title' => 'Astrox 99 Pro hợp với ai', 'hook' => 'Ai nên chọn mẫu này',
                    'caption' => 'Bản 4U nặng trung bình 83g.', 'hashtags' => ['#chonvot', '#caulong', '#jerrybadminton'],
                    'source_ids' => [], 'product_ids' => ['p'.$this->productId], 'viewer_question' => 'Vợt này hợp với ai?', 'demand_basis' => 'hypothesis',
                    'slides' => array_map(fn ($i) => ['heading' => "Ý {$i}", 'body' => "Phân tích dựa trên thông số có trong dữ liệu, phần {$i}."], range(1, 4)),
                    'review_reason' => null,
                ]]], 'spy', null, 1, 1, null);
            }
        };
    }
}
