<?php

namespace App\Services\ContentStudio\Publishers;

use App\Enums\ContentStudio\ContentStatus;
use App\Models\ContentStudio\ContentAsset;
use App\Models\ContentStudio\ContentItem;
use App\Services\ContentStudio\Ops\ContentEventLogger;
use App\Services\ContentStudio\Quality\QualityGate;
use App\Services\ContentStudio\Render\CarouselProducer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Publisher" for when no posting API is available (Metricool API needs a paid plan,
 * TikTok Direct Post needs an audited app). It posts NOTHING. It writes a package a
 * person can upload in a couple of minutes:
 *
 *   exports/{plan_date}/{id}-{slug}/  01.png ... caption.txt  qa.json  report.json
 *
 * and says plainly that nothing is scheduled. The item stays `ready`.
 */
final class ManualExportPublisher
{
    public function __construct(
        private readonly CarouselProducer $producer,
        private readonly QualityGate $gate,
        private readonly ContentEventLogger $events,
    ) {}

    public function directory(ContentItem $item): string
    {
        $date = $item->brief_json['plan_date'] ?? now()->toDateString();

        return config('content-studio.storage.prefix')."/exports/{$date}/{$item->id}-".Str::limit(Str::slug((string) $item->title) ?: 'post', 50, '');
    }

    /**
     * @return array{directory: string, files: list<string>}
     *
     * @throws RuntimeException when the item is not ready or its render is incomplete
     */
    public function export(ContentItem $item): array
    {
        if ($item->status !== ContentStatus::Ready) {
            throw new RuntimeException("Only a ready item can be exported (item {$item->id} is {$item->status->value}).");
        }
        $version = $this->producer->currentVersion($item);
        $manifest = $this->producer->readManifest($item, $version);
        if ($manifest === null) {
            throw new RuntimeException("Item {$item->id} has no complete render to export.");
        }

        $storage = Storage::disk($this->producer->disk());
        $dir = $this->directory($item);
        $files = [];

        foreach ($manifest['slides'] as $slide) {
            $target = sprintf('%s/%02d.png', $dir, $slide['index']);
            $storage->put($target, (string) $storage->get($slide['path']));
            $files[] = $target;
        }

        $caption = $this->gate->finalCaption($item);
        $storage->put("{$dir}/caption.txt", $caption."\n");
        $files[] = "{$dir}/caption.txt";

        $qa = $this->gate->evaluate($item)->toArray();
        $storage->put("{$dir}/qa.json", $this->json($qa));
        $files[] = "{$dir}/qa.json";

        $storage->put("{$dir}/report.json", $this->json($this->report($item, $manifest, $files, $caption, $qa)));
        $files[] = "{$dir}/report.json";

        ContentAsset::updateOrCreate(
            ['content_item_id' => $item->id, 'kind' => 'export_package', 'path' => "{$dir}/report.json"],
            ['storage_disk' => $this->producer->disk(), 'mime' => 'application/json', 'license_state' => 'original'],
        );
        $this->events->log('content.exported', 'content_item', $item->id, ['directory' => $dir, 'files' => count($files), 'qa' => $qa['verdict']]);

        return ['directory' => $dir, 'files' => $files];
    }

    /**
     * Shape follows skill `tiktok-growth-studio` (references/storage-and-report.md): fields we cannot
     * honestly fill are null with a reason, never invented.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $files
     * @param  array<string, mixed>  $qa
     * @return array<string, mixed>
     */
    private function report(ContentItem $item, array $manifest, array $files, string $caption, array $qa): array
    {
        $sources = $item->sources()->get();

        return [
            'id' => 'content-item-'.$item->id,
            'bucket' => 'schedule',
            'media_type' => 'carousel',
            // A proposal, not a schedule: no publisher is connected to this package.
            'publish_at' => null,
            'publish_time_reason' => 'Manual package: nothing has been scheduled. Upload it yourself or connect a publisher.',
            'proposed_publish_at' => $item->brief_json['proposed_publish_at'] ?? null,
            'content' => [
                'title' => $item->title,
                'hook' => $item->hook,
                'summary' => $item->brief_json['viewer_question'] ?? null,
                'caption' => $caption,
            ],
            'src' => [
                'media_paths' => array_values(array_filter($files, fn ($f) => str_ends_with($f, '.png'))),
                'source_urls' => $sources->pluck('canonical_url')->all(),
                'related_source_ids' => $sources->pluck('id')->all(),
            ],
            'trend_classification' => $item->brief_json['demand_basis'] ?? 'hypothesis',
            'trend_evidence' => null,
            'rights_status' => 'owned',
            'rights_note' => 'Slides are drawn by us from our own text; no third-party image, video or music is used.',
            'qa_status' => $qa['verdict'],
            'schedule_status' => 'awaiting_manual_scheduling',
            'audio' => [
                'current_asset_audio' => 'none',
                'listening_status' => 'not_applicable',
                'research_status' => 'not_done',
                'selected_track' => null,
                'implementation_status' => 'pending_platform_attachment',
                'remaining_steps' => ['Optionally pick licensed music in the TikTok app when posting. No track has been chosen.'],
            ],
            'hashtags' => array_map(fn ($tag) => ['tag' => $tag, 'reason' => null, 'evidence_status' => 'topic_relevance_only'], (array) $item->hashtags_json),
            'render' => ['driver' => $manifest['driver'], 'version' => $manifest['version'], 'prompt_version' => $manifest['prompt_version'] ?? null],
            'platform_post_id' => null,
            'planner_url' => null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
