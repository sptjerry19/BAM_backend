<?php

namespace App\Services\ContentStudio\Ai;

use App\Services\ContentStudio\Contracts\AiClient;
use App\Services\ContentStudio\Planning\TitleSimilarity;
use Carbon\Carbon;

/**
 * Deterministic stand-in for OpenAI: used by `--mock-ai` and by tests. Costs
 * nothing and needs no key. It writes plain, generic, number-free text and
 * marks every item with `review_reason`, so mock output can never be
 * mistaken for approved content: the quality gate sends it to review.
 */
final class MockAiClient implements AiClient
{
    public const REVIEW_REASON = 'MOCK AI output: not written by a model, review before use';

    public function __construct(private readonly TitleSimilarity $similarity = new TitleSimilarity()) {}

    public function generateStructured(AiRequest $request): AiResult
    {
        $in = $request->input;
        $items = [];
        $skipped = [];

        foreach ((array) ($in['slots_requested'] ?? []) as $slot) {
            $item = match ($slot) {
                'news' => $this->news($in),
                'equipment' => ($in['equipment']['mode'] ?? 'guide') === 'guide' ? $this->equipment($in) : null,
                'tutorial' => $this->tutorial($in),
                default => null,
            };
            if ($item === null) {
                $skipped[] = ['slot' => (string) $slot, 'reason' => 'mock client does not generate this slot'];
            } else {
                $items[] = $item;
            }
        }

        return new AiResult(['date' => (string) ($in['date'] ?? ''), 'items' => $items, 'skipped_slots' => $skipped], 'mock', null, 0, 0, 0.0, true);
    }

    /** @param array<string, mixed> $in @return array<string, mixed>|null */
    private function news(array $in): ?array
    {
        $source = ($in['verified_sources'] ?? [])[0] ?? null;
        if ($source === null) {
            return null;
        }
        $publisher = (string) $source['publisher'];
        $date = Carbon::parse($source['published_at'])->setTimezone((string) ($in['timezone'] ?? 'UTC'))->format('d/m/Y');
        $chunks = $this->chunks((string) $source['summary'], 200);

        $slides = [['heading' => $this->cut((string) $source['title'], 60), 'body' => "Nguồn: {$publisher}, ngày {$date}."]];
        foreach (array_slice($chunks, 0, 2) as $i => $chunk) {
            $slides[] = ['heading' => $i === 0 ? 'Điều đã được công bố' : 'Chi tiết từ nguồn', 'body' => $chunk];
        }
        $slides[] = ['heading' => 'Theo dõi để không bỏ lỡ', 'body' => 'Jerry Badminton cập nhật tin cầu lông thường xuyên. Bấm theo dõi để xem tiếp.'];
        $slides[] = ['heading' => 'Nguồn', 'body' => "Nguồn: {$publisher}. Nội dung được viết lại bằng lời của kênh."];

        return $this->item('news', '[MOCK] '.$this->cut((string) $source['title'], 80), $this->cut((string) $source['title'], 110),
            'Tin cầu lông từ '.$publisher.' ngày '.$date.': '.$this->cut($chunks[0] ?? '', 300)."\n\nNguồn: {$publisher}",
            ['#caulong', '#badminton', '#jerrybadminton'], $slides, [(string) $source['id']],
            'Có gì mới trong tin cầu lông này?');
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function equipment(array $in): array
    {
        $topic = $this->pickTopic((array) ($in['equipment']['topics'] ?? ['Chọn vợt cầu lông']), (array) ($in['recent_titles'] ?? []));

        return $this->item('equipment', '[MOCK] '.$topic, $this->cut($topic, 110),
            "Chọn vợt cầu lông không cần phức tạp: {$topic}. Lưu lại để dùng khi đi mua vợt.",
            ['#chonvot', '#caulong', '#jerrybadminton'],
            [
                ['heading' => $this->cut($topic, 60), 'body' => 'Nếu bạn đang phân vân khi chọn vợt, đây là cách nghĩ đơn giản.'],
                ['heading' => 'Bắt đầu từ nhu cầu', 'body' => 'Xác định trình độ, lối chơi thiên công hay thủ và ngân sách trước khi nhìn vào thông số.'],
                ['heading' => 'Đánh đổi luôn tồn tại', 'body' => 'Vợt nhẹ dễ xoay vợt nhưng có thể kém lực. Vợt nặng đầu hỗ trợ lực nhưng nhanh mỏi tay hơn.'],
                ['heading' => 'Thử trước khi mua', 'body' => 'Nếu có thể, hãy cầm thử và đánh vài cú để cảm nhận trước khi quyết định.'],
                ['heading' => 'Theo dõi series chọn vợt', 'body' => 'Kênh sẽ ra thêm các bài hướng dẫn chọn trang bị. Theo dõi để xem tiếp.'],
            ],
            [], 'Nên chọn vợt như thế nào cho phù hợp với mình?');
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function tutorial(array $in): array
    {
        $topic = $this->pickTopic((array) ($in['topics']['tutorial'] ?? ['Kỹ thuật cầu lông']), (array) ($in['recent_titles'] ?? []));

        return $this->item('tutorial', '[MOCK] '.$topic, $this->cut($topic, 110),
            "Mẹo kỹ thuật cho người chơi phong trào: {$topic}. Lưu lại để tập dần.",
            ['#kythuatcaulong', '#caulong', '#jerrybadminton'],
            [
                ['heading' => $this->cut($topic, 60), 'body' => 'Một mẹo nhỏ giúp động tác gọn và bớt tốn sức hơn.'],
                ['heading' => 'Lỗi thường gặp', 'body' => 'Nhiều người dồn lực vào cánh tay thay vì dùng cả cơ thể.'],
                ['heading' => 'Cách sửa', 'body' => 'Tập chậm, chú ý chuyển trọng tâm và thời điểm chạm cầu trước khi tăng tốc.'],
                ['heading' => 'Bài tập nhỏ', 'body' => 'Mỗi buổi dành vài phút tập riêng một động tác và quay video để tự xem lại.'],
                ['heading' => 'Theo dõi để xem tiếp', 'body' => 'Kênh sẽ có thêm mẹo kỹ thuật mỗi tuần. Bấm theo dõi để không bỏ lỡ.'],
            ],
            [], 'Làm sao để thực hiện động tác này đúng hơn?');
    }

    /**
     * @param  list<string>  $hashtags
     * @param  list<array{heading: string, body: string}>  $slides
     * @param  list<string>  $sourceIds
     * @return array<string, mixed>
     */
    private function item(string $slot, string $title, string $hook, string $caption, array $hashtags, array $slides, array $sourceIds, string $question): array
    {
        return [
            'slot' => $slot, 'format' => 'carousel', 'title' => $title, 'hook' => $hook, 'caption' => $caption,
            'hashtags' => $hashtags, 'source_ids' => $sourceIds, 'product_ids' => [],
            'viewer_question' => $question, 'demand_basis' => 'evergreen', 'slides' => $slides,
            'review_reason' => self::REVIEW_REASON,
        ];
    }

    /** @param list<string> $topics @param list<string> $recent */
    private function pickTopic(array $topics, array $recent): string
    {
        foreach ($topics as $topic) {
            if ($this->similarity->mostSimilar($topic, $recent) < 0.5) {
                return $topic;
            }
        }

        return $topics[0];
    }

    /** @return list<string> sentences grouped into pieces of at most $max characters */
    private function chunks(string $text, int $max): array
    {
        $out = [];
        $current = '';
        foreach (preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [] as $sentence) {
            if ($current !== '' && mb_strlen($current.' '.$sentence) > $max) {
                $out[] = $current;
                $current = '';
            }
            $current = trim($current.' '.$this->cut($sentence, $max));
        }
        if ($current !== '') {
            $out[] = $current;
        }

        return $out === [] ? [$this->cut($text, $max)] : $out;
    }

    private function cut(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max * 0.5 ? mb_substr($cut, 0, $space) : $cut, " ,;:.-").'…';
    }
}
