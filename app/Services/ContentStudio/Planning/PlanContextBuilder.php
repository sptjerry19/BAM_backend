<?php

namespace App\Services\ContentStudio\Planning;

use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentSource;
use App\Services\ContentStudio\Sources\EquipmentProvider;
use Carbon\CarbonInterface;

final class PlanContextBuilder
{
    public function __construct(private readonly EquipmentProvider $equipment) {}

    /**
     * @param  list<string>  $wantedSlots
     */
    public function build(ContentChannel $channel, CarbonInterface $date, array $wantedSlots): PlanContext
    {
        $requested = $wantedSlots;
        $shortfalls = [];

        // News is only offered when there is a recent, official, detailed, unused source.
        $sources = in_array('news', $wantedSlots, true) ? $this->newsSources() : [];
        if (in_array('news', $wantedSlots, true) && $sources === []) {
            $requested = array_values(array_diff($requested, ['news']));
            $shortfalls[] = [
                'slot' => 'news',
                'reason' => sprintf(
                    'no_verified_recent_source: no unused official news item published in the last %d days (feeds configured: %d)',
                    (int) config('content-studio.news.recent_days'),
                    count((array) config('content-studio.news.feeds')),
                ),
            ];
        }

        $products = in_array('equipment', $requested, true) ? $this->equipment->productModeCandidates() : [];

        return new PlanContext(
            date: $date->toDateString(),
            slotsRequested: $requested,
            shortfalls: $shortfalls,
            sources: $sources,
            products: $products,
            equipmentMode: $products === [] ? 'guide' : 'product',
            recentTitles: $this->recentTitles($channel),
        );
    }

    /** @return list<array<string, mixed>> */
    private function newsSources(): array
    {
        $min = (int) config('content-studio.news.min_summary_chars');
        $recent = (int) config('content-studio.news.recent_days');

        $rows = ContentSource::query()
            ->where('bucket', 'news')
            ->where('trust', 'official')
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now()->subDays($recent))
            // a source that already drives a live post is not offered again
            ->whereDoesntHave('items', fn ($q) => $q->where('content_items.status', '!=', 'cancelled'))
            ->orderByDesc('published_at')
            ->limit(15)
            ->get();

        return $rows
            ->filter(fn (ContentSource $s) => mb_strlen((string) $s->summary) >= $min)
            ->map(fn (ContentSource $s) => [
                'id' => 's'.$s->id,
                'source_id' => $s->id,
                'publisher' => (string) $s->creator,
                'title' => (string) $s->title,
                'summary' => mb_substr((string) $s->summary, 0, 600),
                'published_at' => $s->published_at->toIso8601String(),
                'published_ts' => $s->published_at->getTimestamp(),
                'url' => $s->canonical_url,
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function recentTitles(ContentChannel $channel): array
    {
        $days = (int) config('content-studio.editorial.duplicate_window_days');

        return ContentItem::query()
            ->where('channel_id', $channel->id)
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('title')
            ->orderByDesc('id')
            ->limit(60)
            ->pluck('title')
            ->all();
    }
}
