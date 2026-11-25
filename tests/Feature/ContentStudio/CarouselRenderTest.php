<?php

namespace Tests\Feature\ContentStudio;

use App\Models\ContentStudio\ContentAsset;
use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentSource;
use App\Services\ContentStudio\Contracts\CarouselRenderer;
use App\Services\ContentStudio\Render\CarouselProducer;
use App\Services\ContentStudio\Render\CarouselSpec;
use App\Services\ContentStudio\Render\CarouselSpecBuilder;
use App\Services\ContentStudio\Render\GdCarouselRenderer;
use App\Services\ContentStudio\Render\PlaywrightCarouselRenderer;
use App\Services\ContentStudio\Render\RenderException;
use App\Services\ContentStudio\Render\RendererFactory;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class CarouselRenderTest extends TestCase
{
    use UsesContentStudioDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['content-studio.storage.disk' => 'local', 'content-studio.render.driver' => 'gd']);
    }

    private function item(?array $slides = null, string $slot = 'equipment', array $over = []): ContentItem
    {
        $slides ??= [
            ['heading' => 'Vợt 3U hay 4U?', 'body' => 'Cách nghĩ đơn giản khi chọn vợt cho người mới.'],
            ['heading' => 'Bắt đầu từ nhu cầu', 'body' => 'Xác định trình độ, lối chơi và ngân sách trước khi nhìn vào thông số.'],
            ['heading' => 'Đánh đổi luôn tồn tại', 'body' => 'Vợt nhẹ dễ xoay nhưng có thể kém lực. Vợt nặng đầu hỗ trợ lực nhưng nhanh mỏi tay hơn.'],
            ['heading' => 'Theo dõi series chọn vợt', 'body' => 'Kênh sẽ ra thêm các bài hướng dẫn chọn trang bị.'],
        ];

        return ContentItem::create($over + [
            'channel_id' => ContentChannel::default()->id, 'slot' => $slot, 'content_type' => 'carousel',
            'title' => 'Vợt 3U hay 4U', 'hook' => 'Hook', 'caption' => 'Caption', 'script_json' => ['slides' => $slides],
            'brief_json' => ['render_version' => 1, 'prompt_version' => 'planner-abc'],
        ]);
    }

    private function producer(): CarouselProducer
    {
        return app(CarouselProducer::class);
    }

    // ----- spec ------------------------------------------------------------

    public function test_the_spec_numbers_slides_and_marks_hook_and_closing(): void
    {
        $spec = app(CarouselSpecBuilder::class)->fromItem($this->item());

        $this->assertSame(['hook', 'content', 'content', 'closing'], array_column($spec->slides, 'kind'));
        $this->assertSame([1, 2, 3, 4], array_column($spec->slides, 'index'));
        $this->assertSame(4, $spec->slides[0]['total']);
        $this->assertSame('CHỌN TRANG BỊ', $spec->slotLabel);
        $this->assertSame('@jerrybadminton', $spec->footer);
        $this->assertSame([1080, 1920], [$spec->width, $spec->height]);
    }

    public function test_news_slides_carry_the_source_credit_and_date_in_the_footer(): void
    {
        $item = $this->item(slot: 'news');
        $source = ContentSource::create([
            'platform' => 'web', 'bucket' => 'news', 'canonical_url' => 'https://e.test/a', 'url_hash' => ContentSource::hashUrl('https://e.test/a'),
            'creator' => 'Badminton Asia', 'published_at' => '2026-07-05 09:18:26',
        ]);
        $item->sources()->attach($source->id, ['usage' => 'fact']);

        $this->assertSame('Nguồn: Badminton Asia · 05/07/2026', app(CarouselSpecBuilder::class)->fromItem($item)->footer);
    }

    public function test_emoji_and_invisible_characters_are_removed_because_the_fonts_cannot_draw_them(): void
    {
        $item = $this->item([['heading' => "Smash 🏸🔥 mạnh\u{200B}", 'body' => "Tập đều ✅ mỗi ngày\u{FE0F}"]]);

        $slide = app(CarouselSpecBuilder::class)->fromItem($item)->slides[0];

        $this->assertSame('Smash mạnh', $slide['heading']);
        $this->assertSame('Tập đều mỗi ngày', $slide['body']);
    }

    public function test_an_item_without_usable_slides_cannot_be_rendered(): void
    {
        $this->expectException(RenderException::class);
        app(CarouselSpecBuilder::class)->fromItem($this->item([], over: ['script_json' => ['slides' => []]]));
    }

    public function test_the_spec_hash_changes_with_the_text_and_not_otherwise(): void
    {
        $builder = app(CarouselSpecBuilder::class);
        $a = $builder->fromItem($this->item());
        $b = $builder->fromItem($this->item());
        $c = $builder->fromItem($this->item([['heading' => 'Khác', 'body' => 'Nội dung khác hẳn.']]));

        $this->assertSame($a->hash(), $b->hash());
        $this->assertNotSame($a->hash(), $c->hash());
    }

    // ----- GD renderer -----------------------------------------------------

    public function test_gd_draws_one_png_per_slide_at_the_full_size(): void
    {
        $spec = app(CarouselSpecBuilder::class)->fromItem($this->item());
        $dir = sys_get_temp_dir().'/cs-gd-test-'.uniqid();

        $files = app(GdCarouselRenderer::class)->render($spec, $dir);

        $this->assertCount(4, $files);
        foreach ($files as $i => $file) {
            $this->assertSame(sprintf('%02d.png', $i + 1), basename($file));
            $size = getimagesize($file);
            $this->assertSame([1080, 1920, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]]);
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function test_gd_refuses_text_that_cannot_fit_instead_of_clipping_it(): void
    {
        $long = trim(str_repeat('Một đoạn rất dài không thể nào nhét vừa một slide. ', 40));
        $spec = app(CarouselSpecBuilder::class)->fromItem($this->item([['heading' => 'Tiêu đề', 'body' => $long]]));

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('does not fit');
        app(GdCarouselRenderer::class)->render($spec, sys_get_temp_dir().'/cs-gd-test-'.uniqid());
    }

    public function test_gd_breaks_a_word_that_is_wider_than_the_slide(): void
    {
        $spec = app(CarouselSpecBuilder::class)->fromItem($this->item([['heading' => str_repeat('W', 60), 'body' => 'ok']]));
        $dir = sys_get_temp_dir().'/cs-gd-test-'.uniqid();

        $files = app(GdCarouselRenderer::class)->render($spec, $dir);

        $this->assertFileExists($files[0]);
        array_map('unlink', $files);
        @rmdir($dir);
    }

    public function test_a_missing_font_is_a_clear_error(): void
    {
        config(['content-studio.render.fonts.bold' => '/nope/missing.ttf']);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('Font file');
        app(GdCarouselRenderer::class)->render(app(CarouselSpecBuilder::class)->fromItem($this->item()), sys_get_temp_dir().'/cs-x');
    }

    // ----- factory / playwright -------------------------------------------

    public function test_the_factory_picks_the_configured_driver_and_rejects_unknown_ones(): void
    {
        $this->assertSame('gd', app(RendererFactory::class)->make()->name());
        $this->assertSame('playwright', app(RendererFactory::class)->make('playwright')->name());

        $this->expectException(RenderException::class);
        app(RendererFactory::class)->make('imagemagick');
    }

    public function test_the_playwright_driver_fails_clearly_outside_the_media_image(): void
    {
        config(['content-studio.render.script' => '/nope/render_carousel.py']);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('content-media');
        app(PlaywrightCarouselRenderer::class)->render(app(CarouselSpecBuilder::class)->fromItem($this->item()), sys_get_temp_dir().'/cs-pw');
    }

    // ----- producer --------------------------------------------------------

    public function test_producing_stores_slides_assets_and_a_manifest(): void
    {
        $item = $this->item();

        $result = $this->producer()->produce($item);

        $this->assertFalse($result['reused']);
        $this->assertSame(1, $result['version']);
        $dir = "content-studio/items/{$item->id}/v1";
        foreach (['01.png', '02.png', '03.png', '04.png', 'manifest.json'] as $name) {
            Storage::disk('local')->assertExists("{$dir}/{$name}");
        }

        $manifest = json_decode(Storage::disk('local')->get("{$dir}/manifest.json"), true);
        $this->assertSame(['gd', 1, $item->id, 'planner-abc'], [$manifest['driver'], $manifest['version'], $manifest['item_id'], $manifest['prompt_version']]);
        $this->assertCount(4, $manifest['slides']);
        foreach ($manifest['slides'] as $slide) {
            $this->assertSame($slide['sha256'], hash('sha256', Storage::disk('local')->get($slide['path'])), 'manifest checksums match the stored bytes');
            $this->assertSame([1080, 1920], [$slide['width'], $slide['height']]);
        }

        $assets = ContentAsset::where('content_item_id', $item->id)->orderBy('path')->get();
        $this->assertCount(4, $assets);
        $this->assertSame(['carousel_slide', 'original', 'image/png', 'local'], [$assets[0]->kind, $assets[0]->license_state, $assets[0]->mime, $assets[0]->storage_disk]);
    }

    public function test_producing_twice_reuses_the_render_and_changes_nothing(): void
    {
        $item = $this->item();
        $first = $this->producer()->produce($item);
        $second = $this->producer()->produce($item->fresh());

        $this->assertTrue($second['reused']);
        $this->assertSame($first['manifest']['generated_at'], $second['manifest']['generated_at']);
        $this->assertSame(4, ContentAsset::count());
    }

    public function test_changed_text_gets_a_new_version_and_the_old_one_is_left_alone(): void
    {
        $item = $this->item();
        $this->producer()->produce($item);
        $v1 = Storage::disk('local')->get("content-studio/items/{$item->id}/v1/01.png");

        $item->update(['script_json' => ['slides' => [['heading' => 'Tiêu đề mới', 'body' => 'Nội dung đã sửa.']]]]);
        $result = $this->producer()->produce($item->fresh());

        $this->assertSame(2, $result['version']);
        $this->assertSame(2, $item->fresh()->brief_json['render_version']);
        Storage::disk('local')->assertExists("content-studio/items/{$item->id}/v2/manifest.json");
        $this->assertSame($v1, Storage::disk('local')->get("content-studio/items/{$item->id}/v1/01.png"), 'v1 is immutable');
    }

    public function test_force_makes_a_new_version_even_if_nothing_changed(): void
    {
        $item = $this->item();
        $this->producer()->produce($item);

        $result = $this->producer()->produce($item->fresh(), force: true);

        $this->assertSame([2, false], [$result['version'], $result['reused']]);
    }

    public function test_a_manifest_with_a_missing_slide_is_not_trusted(): void
    {
        $item = $this->item();
        $this->producer()->produce($item);
        Storage::disk('local')->delete("content-studio/items/{$item->id}/v1/03.png");

        $this->assertNull($this->producer()->readManifest($item->fresh(), 1));
    }

    public function test_a_renderer_that_returns_the_wrong_size_never_gets_a_manifest(): void
    {
        // the factory resolves the `gd` driver through the container, so a broken renderer can be swapped in
        $this->app->bind(GdCarouselRenderer::class, fn () => new class implements CarouselRenderer
        {
            public function name(): string
            {
                return 'broken';
            }

            public function render(CarouselSpec $spec, string $outDir): array
            {
                @mkdir($outDir, 0775, true);
                imagepng(imagecreatetruecolor(500, 500), $outDir.'/01.png');

                return [$outDir.'/01.png'];
            }
        });
        $item = $this->item();

        try {
            $this->producer()->produce($item);
            $this->fail('a wrong-sized slide must be rejected');
        } catch (RenderException $e) {
            $this->assertStringContainsString('1080x1920', $e->getMessage());
        }

        Storage::disk('local')->assertMissing("content-studio/items/{$item->id}/v1/manifest.json");
        $this->assertSame(0, ContentAsset::count());
    }

    public function test_temporary_files_are_removed_even_when_rendering_fails(): void
    {
        $before = glob(sys_get_temp_dir().'/content-studio-*') ?: [];
        config(['content-studio.render.fonts.bold' => '/nope/missing.ttf']);

        try {
            $this->producer()->produce($this->item());
        } catch (RenderException) {
        }

        $this->assertSame($before, glob(sys_get_temp_dir().'/content-studio-*') ?: []);
    }
}
