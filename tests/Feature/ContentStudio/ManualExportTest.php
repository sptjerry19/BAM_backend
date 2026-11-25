<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Models\ContentStudio\ContentAsset;
use App\Services\ContentStudio\Publishers\ManualExportPublisher;
use App\Services\ContentStudio\Quality\QualityGate;
use App\Services\ContentStudio\Render\CarouselProducer;
use App\Services\ContentStudio\State\ContentStateMachine;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\MakesContentItems;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class ManualExportTest extends TestCase
{
    use MakesContentItems;
    use UsesContentStudioDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeStorage();
    }

    private function readyItem(array $over = [])
    {
        $item = $this->cleanItem('tutorial', $over);
        app(CarouselProducer::class)->produce($item);

        return app(ContentStateMachine::class)->advanceTo($item->fresh(), ContentStatus::Ready);
    }

    public function test_it_writes_a_complete_package_and_posts_nothing(): void
    {
        $item = $this->readyItem();

        $result = app(ManualExportPublisher::class)->export($item);

        $dir = $result['directory'];
        $this->assertStringStartsWith('content-studio/exports/2026-11-08/'.$item->id.'-', $dir);
        foreach (['01.png', '02.png', '03.png', '04.png', '05.png', 'caption.txt', 'qa.json', 'report.json'] as $file) {
            Storage::disk('local')->assertExists("{$dir}/{$file}");
        }
        $this->assertCount(8, $result['files']);
        $this->assertSame(ContentStatus::Ready, $item->fresh()->status, 'exporting does not change the status');
        $this->assertNull($item->fresh()->scheduled_at);
        $this->assertSame(1, ContentAsset::where('kind', 'export_package')->count());
    }

    public function test_caption_txt_is_exactly_what_would_be_posted(): void
    {
        $item = $this->readyItem();

        $dir = app(ManualExportPublisher::class)->export($item)['directory'];

        $this->assertSame(app(QualityGate::class)->finalCaption($item)."\n", Storage::disk('local')->get("{$dir}/caption.txt"));
        $this->assertStringContainsString('#caulong #kythuat #jerrybadminton', Storage::disk('local')->get("{$dir}/caption.txt"));
    }

    public function test_the_exported_slides_are_byte_identical_to_the_rendered_ones(): void
    {
        $item = $this->readyItem();
        $manifest = app(CarouselProducer::class)->readManifest($item, 1);

        $dir = app(ManualExportPublisher::class)->export($item)['directory'];

        foreach ($manifest['slides'] as $slide) {
            $this->assertSame($slide['sha256'], hash('sha256', Storage::disk('local')->get(sprintf('%s/%02d.png', $dir, $slide['index']))));
        }
    }

    public function test_report_json_follows_the_skill_contract_and_never_claims_a_schedule(): void
    {
        $item = $this->readyItem();

        $dir = app(ManualExportPublisher::class)->export($item)['directory'];
        $report = json_decode(Storage::disk('local')->get("{$dir}/report.json"), true);

        $this->assertSame(['schedule', 'carousel', 'awaiting_manual_scheduling', 'ready'], [$report['bucket'], $report['media_type'], $report['schedule_status'], $report['qa_status']]);
        $this->assertNull($report['publish_at'], 'nothing is scheduled');
        $this->assertStringContainsString('nothing has been scheduled', $report['publish_time_reason']);
        $this->assertSame('2026-11-08T10:30:00+00:00', $report['proposed_publish_at'], 'the time is only a proposal');
        $this->assertNull($report['platform_post_id']);
        $this->assertNull($report['planner_url']);
        $this->assertSame(['none', 'pending_platform_attachment', null], [$report['audio']['current_asset_audio'], $report['audio']['implementation_status'], $report['audio']['selected_track']], 'no music is claimed');
        $this->assertCount(5, $report['src']['media_paths']);
        $this->assertSame('owned', $report['rights_status']);
        $this->assertSame(['#caulong', '#kythuat', '#jerrybadminton'], array_column($report['hashtags'], 'tag'));
        $this->assertNull($report['hashtags'][0]['reason'], 'no invented reasons');
        $this->assertSame('gd', $report['render']['driver']);
        $this->assertSame('evergreen', $report['trend_classification']);
        $this->assertNull($report['trend_evidence']);
    }

    public function test_a_news_package_lists_its_source_urls(): void
    {
        $source = $this->newsSource(['canonical_url' => 'https://badmintonasia.org/news/abc']);
        $item = $this->newsItem($source);
        app(CarouselProducer::class)->produce($item);
        $item = app(ContentStateMachine::class)->advanceTo($item->fresh(), ContentStatus::Ready);

        $dir = app(ManualExportPublisher::class)->export($item)['directory'];
        $report = json_decode(Storage::disk('local')->get("{$dir}/report.json"), true);

        $this->assertSame(['https://badmintonasia.org/news/abc'], $report['src']['source_urls']);
        $this->assertSame([$source->id], $report['src']['related_source_ids']);
    }

    public function test_exporting_twice_overwrites_the_same_package_without_duplicates(): void
    {
        $item = $this->readyItem();
        $exporter = app(ManualExportPublisher::class);

        $a = $exporter->export($item)['directory'];
        $b = $exporter->export($item)['directory'];

        $this->assertSame($a, $b);
        $this->assertSame(1, ContentAsset::where('kind', 'export_package')->count());
        $this->assertCount(1, collect(Storage::disk('local')->allDirectories('content-studio/exports'))->filter(fn ($d) => str_contains($d, "/{$item->id}-")));
    }

    public function test_only_a_ready_item_with_a_complete_render_can_be_exported(): void
    {
        $notReady = $this->cleanItem();
        app(CarouselProducer::class)->produce($notReady);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a ready item');
        app(ManualExportPublisher::class)->export($notReady->fresh());
    }

    public function test_a_ready_item_whose_render_disappeared_is_not_exported(): void
    {
        $item = $this->readyItem();
        Storage::disk('local')->deleteDirectory("content-studio/items/{$item->id}");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no complete render');
        app(ManualExportPublisher::class)->export($item);
    }

    public function test_the_folder_name_is_filesystem_safe_for_vietnamese_titles(): void
    {
        $item = $this->readyItem(['title' => 'Đánh đôi: xoay vị trí/“chuẩn” <script> ??? ../../etc']);

        $dir = app(ManualExportPublisher::class)->directory($item);

        $this->assertMatchesRegularExpression('#^content-studio/exports/2026-11-08/\d+-[a-z0-9-]+$#', $dir);
        $this->assertStringNotContainsString('..', $dir);
    }
}
