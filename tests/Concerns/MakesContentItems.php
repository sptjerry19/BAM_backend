<?php

namespace Tests\Concerns;

use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPlan;
use App\Models\ContentStudio\ContentSource;
use Illuminate\Support\Facades\Storage;

/** Builds content that passes every gate, so a test only changes the one thing it is about. */
trait MakesContentItems
{
    protected function useFakeStorage(): void
    {
        Storage::fake('local');
        config([
            'content-studio.storage.disk' => 'local',
            'content-studio.render.driver' => 'gd',
            'content-studio.queues.connection' => 'sync', // jobs run inline at dispatch
        ]);
    }

    /** @return list<array{heading: string, body: string}> */
    protected function cleanSlides(int $n = 5): array
    {
        $topics = ['Bắt đầu từ nhu cầu', 'Đánh đổi luôn tồn tại', 'Thử trước khi mua', 'Điều cần tránh', 'Theo dõi series chọn vợt'];

        return array_map(fn ($i) => [
            'heading' => $topics[$i % count($topics)],
            'body' => 'Phân tích ngắn gọn, dễ áp dụng cho người chơi phong trào, phần '.($i + 1).'.',
        ], range(0, $n - 1));
    }

    protected function plan(string $date = '2026-11-08'): ContentPlan
    {
        return ContentPlan::query()->whereDate('plan_date', $date)->first()
            ?? ContentPlan::create(['channel_id' => ContentChannel::default()->id, 'plan_date' => $date, 'status' => 'planned']);
    }

    /** A non-mock item with clean text: the pipeline takes it all the way to `ready`. */
    protected function cleanItem(string $slot = 'tutorial', array $over = []): ContentItem
    {
        static $n = 0;
        $titles = [
            'Bước chân về giữa sân sau mỗi cú đánh', 'Cầm vợt đúng cách cho người mới bắt đầu', 'Phát cầu thấp thường sai ở đâu',
            'Đánh đôi nên xoay vị trí như thế nào', 'Lỗi hay gặp khi đập cầu bằng cánh tay', 'Tập thể lực ngắn trước buổi chơi',
            'Cách bắt lưới nhanh khi đối thủ bỏ nhỏ', 'Chọn giày phù hợp mặt sân trong nhà', 'Khởi động vai trước khi vung vợt mạnh',
            'Đọc hướng cầu từ động tác của đối phương', 'Giữ nhịp thở ổn định trong trận dài', 'Ghi điểm nhờ những quả cầu cao sâu',
        ];
        $title = $titles[$n % count($titles)].($n >= count($titles) ? ' phần '.intdiv($n, count($titles)) : '');
        $n++;
        $plan = $this->plan();

        return ContentItem::create($over + [
            'plan_id' => $plan->id,
            'channel_id' => $plan->channel_id,
            'slot' => $slot,
            'content_type' => 'carousel',
            'title' => $title,
            'hook' => 'Một mẹo nhỏ giúp động tác gọn hơn',
            'caption' => 'Mẹo nhỏ giúp bạn di chuyển gọn hơn khi chơi cầu lông phong trào.',
            'hashtags_json' => ['#caulong', '#kythuat', '#jerrybadminton'],
            'script_json' => ['slides' => $this->cleanSlides()],
            'brief_json' => [
                'plan_date' => '2026-11-08', 'render_version' => 1, 'prompt_version' => 'planner-test', 'review_reason' => null,
                'viewer_question' => 'Làm sao di chuyển gọn hơn?', 'demand_basis' => 'evergreen', 'mock' => false,
                'proposed_publish_at' => '2026-11-08T10:30:00+00:00',
            ],
        ]);
    }

    protected function newsSource(array $over = []): ContentSource
    {
        $url = $over['canonical_url'] ?? 'https://www.badmintonasia.org/news/'.uniqid();

        return ContentSource::create($over + [
            'platform' => 'web', 'bucket' => 'news', 'canonical_url' => $url, 'url_hash' => ContentSource::hashUrl($url),
            'creator' => 'Badminton Asia', 'title' => 'Tin cầu lông', 'summary' => str_repeat('Nội dung tin tức đủ dài. ', 8),
            'trust' => 'official', 'published_at' => now()->subDay(),
        ]);
    }

    /** A news item whose caption credits the source, as the gate requires. */
    protected function newsItem(?ContentSource $source = null, array $over = []): ContentItem
    {
        $source ??= $this->newsSource();
        $item = $this->cleanItem('news', $over + [
            'caption' => "Tóm tắt tin cầu lông ngày hôm qua.\n\nNguồn: {$source->creator}",
        ]);
        $item->sources()->attach($source->id, ['usage' => 'fact']);

        return $item;
    }
}
