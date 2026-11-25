<?php

namespace App\Services\ContentStudio\Quality;

use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentSource;
use App\Services\ContentStudio\Planning\TitleSimilarity;
use App\Services\ContentStudio\Render\CarouselProducer;
use Illuminate\Support\Facades\Storage;

/**
 * Last gate before an item counts as ready. It re-checks the FINAL text and the
 * stored files, not what the planner promised. `block` failures can only be fixed
 * by producing something different (no source, bad file, no rights); `review`
 * failures are for a human to read and decide.
 */
final class QualityGate
{
    public function __construct(
        private readonly TextRules $rules,
        private readonly TitleSimilarity $similarity,
        private readonly CarouselProducer $producer,
    ) {}

    public function evaluate(ContentItem $item): QualityReport
    {
        $report = new QualityReport();
        $ed = (array) config('content-studio.editorial');

        $slides = (array) ($item->script_json['slides'] ?? []);
        $hashtags = (array) ($item->hashtags_json ?? []);
        $caption = $this->finalCaption($item, $ed);
        $text = $this->rules->viewerText((string) $item->title, (string) $item->hook, $caption, $slides);

        // --- structure & text ---------------------------------------------------
        $report->add('format', $item->content_type === 'carousel', 'block', 'only carousel posts are supported in this phase');
        $report->add('fields', trim((string) $item->title) !== '' && trim((string) $item->hook) !== '' && trim((string) $item->caption) !== '', 'review', 'title, hook and caption must not be empty');
        $report->add('hashtags', $this->hashtagsOk($hashtags, $ed), 'review', sprintf('need %d-%d valid, distinct hashtags', $ed['hashtags_min'], $ed['hashtags_max']));
        $report->add('caption_length', mb_strlen($caption) <= $ed['caption_max'], 'review', "caption with hashtags must be at most {$ed['caption_max']} characters");
        $report->add('slides', $this->slidesOk($slides, $ed), 'review', sprintf('%d-%d slides, heading <= %d and body <= %d characters', $ed['slides_min'], $ed['slides_max'], $ed['slide_heading_max'], $ed['slide_body_max']));

        $forbidden = $this->rules->findPhrases($ed['forbidden_phrases'], $text);
        $report->add('claims', $forbidden === [], 'review', 'unsupported claim: '.implode(', ', $forbidden));
        if ($item->slot->value !== 'news') {
            $newsOnly = $this->rules->findPhrases($ed['news_only_phrases'], $text);
            $report->add('news_phrases', $newsOnly === [], 'review', 'news-only wording outside a news post: '.implode(', ', $newsOnly));
        }
        // --- sources & rights ------------------------------------------------------
        $this->checkSources($item, $report, $caption);

        // --- equipment: no invented specs or prices ---------------------------------------
        if ($item->slot->value === 'equipment') {
            $this->checkEquipment($item, $report, $text);
        }

        // --- files ---------------------------------------------------------------------
        $this->checkAssets($item, $report, count($slides));

        // --- repeats -------------------------------------------------------------------
        $similar = $this->mostSimilarRecent($item, (int) $ed['duplicate_window_days']);
        $report->add('duplicate', $similar < (float) $ed['duplicate_similarity'], 'review', sprintf('title is %.0f%% similar to a recent post', $similar * 100));

        // --- planner doubts / mock output ----------------------------------------------------
        $reason = trim((string) ($item->brief_json['review_reason'] ?? ''));
        $report->add('review_flag', $reason === '', 'review', $reason);

        return $report;
    }

    /**
     * The exact caption that would be posted. A required disclosure is appended HERE, by construction,
     * so it can never be forgotten (and it counts towards the length limit). That is why there is no
     * separate "disclosure present" check: it would only test its own output.
     */
    public function finalCaption(ContentItem $item, ?array $ed = null): string
    {
        $ed ??= (array) config('content-studio.editorial');
        $text = trim((string) $item->caption)."\n\n".implode(' ', (array) ($item->hashtags_json ?? []));
        if ($ed['disclosure_required']) {
            $text .= ' '.$ed['disclosure_text'];
        }

        return trim($text);
    }

    /** @param list<string> $hashtags @param array<string, mixed> $ed */
    private function hashtagsOk(array $hashtags, array $ed): bool
    {
        $count = count($hashtags);
        if ($count < $ed['hashtags_min'] || $count > $ed['hashtags_max'] || $count !== count(array_unique(array_map('mb_strtolower', $hashtags)))) {
            return false;
        }
        foreach ($hashtags as $tag) {
            if (! $this->rules->validHashtag((string) $tag, (int) $ed['hashtag_max_length'])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, string>> $slides @param array<string, mixed> $ed */
    private function slidesOk(array $slides, array $ed): bool
    {
        if (count($slides) < $ed['slides_min'] || count($slides) > $ed['slides_max']) {
            return false;
        }
        foreach ($slides as $slide) {
            $heading = trim((string) ($slide['heading'] ?? ''));
            $body = trim((string) ($slide['body'] ?? ''));
            if ($heading === '' || $body === '' || mb_strlen($heading) > $ed['slide_heading_max'] || mb_strlen($body) > $ed['slide_body_max']) {
                return false;
            }
        }

        return true;
    }

    private function checkSources(ContentItem $item, QualityReport $report, string $caption): void
    {
        $sources = $item->sources()->get();

        if ($item->slot->value === 'news') {
            $okSources = $sources->filter(fn (ContentSource $s) => $s->bucket === 'news' && $s->trust === 'official' && $s->published_at !== null);
            $report->add('news_source', $okSources->isNotEmpty(), 'block', 'a news post needs at least one official, dated news source');

            $named = $okSources->contains(fn (ContentSource $s) => $s->creator && str_contains(mb_strtolower($caption), mb_strtolower($s->creator)));
            $report->add('news_credit', $named && str_contains(mb_strtolower($caption), 'nguồn:'), 'review', 'caption must credit the source ("Nguồn: <publisher>")');
        }

        // A source used as an ASSET (not just as a fact) must be cleared for publishing.
        $assetSources = $sources->filter(fn (ContentSource $s) => $s->pivot->usage === 'asset');
        $blocked = $assetSources->reject(fn (ContentSource $s) => $s->rights_status->allowsPublishing());
        $report->add('source_rights', $blocked->isEmpty(), 'block', 'media from sources without publishing rights: '.$blocked->pluck('id')->implode(', '));
    }

    private function checkEquipment(ContentItem $item, QualityReport $report, string $text): void
    {
        $snapshots = $item->products()->get();
        $allowed = [];
        $priceOk = false;
        $maxAge = now()->subDays((int) config('content-studio.equipment.price_max_age_days'));

        foreach ($snapshots as $snap) {
            $allowed = array_merge($allowed, $this->rules->specTokens(implode(' ', (array) ($snap->facts_snapshot_json['facts'] ?? []))));
            $price = (array) ($snap->price_snapshot_json ?? []);
            // freshness is judged now, not when the plan was made
            $priceOk = $priceOk || (($price['visible'] ?? false) && ! empty($price['synced_at']) && now()->parse($price['synced_at'])->greaterThanOrEqualTo($maxAge));
        }

        $invented = array_values(array_diff($this->rules->specTokens($text), $allowed));
        $report->add('equipment_specs', $invented === [], 'review', 'measurements that are not in the product data: '.implode(', ', $invented));
        $report->add('equipment_price', $priceOk || ! $this->rules->hasCurrency($text), 'review', 'a price is mentioned but no fresh price is on record');
    }

    private function checkAssets(ContentItem $item, QualityReport $report, int $slideCount): void
    {
        $version = $this->producer->currentVersion($item);
        $manifest = $this->producer->readManifest($item, $version);
        $report->add('render', $manifest !== null, 'block', "no complete render for version {$version}");
        if ($manifest === null) {
            return;
        }

        $storage = Storage::disk($this->producer->disk());
        $problems = [];
        if (count($manifest['slides']) !== $slideCount) {
            $problems[] = sprintf('%d images for %d slides', count($manifest['slides']), $slideCount);
        }
        foreach ($manifest['slides'] as $slide) {
            $bytes = $storage->get($slide['path']);
            if ($bytes === null || $bytes === '') {
                $problems[] = "slide {$slide['index']} is empty";

                continue;
            }
            if (hash('sha256', $bytes) !== $slide['sha256']) {
                $problems[] = "slide {$slide['index']} checksum differs from the manifest";
            }
            $size = @getimagesizefromstring($bytes);
            if ($size === false || $size[2] !== IMAGETYPE_PNG || $size[0] !== (int) config('content-studio.render.width') || $size[1] !== (int) config('content-studio.render.height')) {
                $problems[] = "slide {$slide['index']} is not a ".config('content-studio.render.width').'x'.config('content-studio.render.height').' PNG';
            }
        }
        $report->add('assets', $problems === [], 'block', implode('; ', $problems));

        $unlicensed = $item->assets()->where('kind', 'carousel_slide')->where(fn ($q) => $q->whereNull('license_state')->orWhere('license_state', '!=', 'original'))->count();
        $report->add('asset_rights', $unlicensed === 0, 'block', "{$unlicensed} slide image(s) without a clear licence");
    }

    private function mostSimilarRecent(ContentItem $item, int $days): float
    {
        $titles = ContentItem::query()
            ->where('channel_id', $item->channel_id)
            ->where('id', '!=', $item->id)
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('title')
            ->limit(200)
            ->pluck('title');

        return $this->similarity->mostSimilar((string) $item->title, $titles);
    }
}
