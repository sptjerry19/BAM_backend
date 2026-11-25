<?php

namespace App\Services\ContentStudio\Planning;

use App\Services\ContentStudio\Quality\TextRules;

/**
 * Checks what the model returned against what the application offered and
 * against the editorial rules. Structured Outputs guarantees only the shape of
 * the JSON; it proves nothing about facts, limits or rights.
 *
 * An item with any violation is rejected (never "fixed up" silently, apart
 * from harmless normalisation such as trimming and the `#` prefix).
 */
final class PlanValidator
{
    public function __construct(
        private readonly TextRules $rules,
        private readonly TitleSimilarity $similarity,
    ) {}

    /**
     * @param  array<string, mixed>  $plan  decoded model answer
     * @return array{items: list<array<string, mixed>>, rejected: list<array<string, mixed>>, skipped: list<array{slot: string, reason: string}>}
     */
    public function validate(array $plan, PlanContext $ctx): array
    {
        $ed = config('content-studio.editorial');
        $items = [];
        $rejected = [];
        $seenSlots = [];
        $acceptedTitles = [];

        foreach ((array) ($plan['items'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $item = $this->normalize($raw);
            $violations = $this->violations($item, $ctx, $ed, $seenSlots, $acceptedTitles);

            if ($violations !== []) {
                $rejected[] = ['slot' => $item['slot'], 'title' => $item['title'], 'violations' => $violations];

                continue;
            }

            $seenSlots[$item['slot']] = true;
            $acceptedTitles[] = $item['title'];
            $items[] = $item;
        }

        $skipped = [];
        foreach ((array) ($plan['skipped_slots'] ?? []) as $s) {
            if (is_array($s) && isset($s['slot'], $s['reason'])) {
                $skipped[] = ['slot' => (string) $s['slot'], 'reason' => mb_substr((string) $s['reason'], 0, 300)];
            }
        }

        return ['items' => $items, 'rejected' => $rejected, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $ed
     * @param  array<string, true>  $seenSlots
     * @param  list<string>  $acceptedTitles
     * @return list<string>
     */
    private function violations(array $item, PlanContext $ctx, array $ed, array $seenSlots, array $acceptedTitles): array
    {
        $v = [];
        $slot = $item['slot'];

        if (! in_array($slot, ['news', 'equipment', 'tutorial', 'meme'], true) || ! in_array($slot, $ctx->slotsRequested, true)) {
            $v[] = 'slot_not_requested';
        }
        if (isset($seenSlots[$slot])) {
            $v[] = 'slot_duplicate';
        }
        if ($item['format'] !== 'carousel') {
            $v[] = 'format_unsupported';
        }

        // --- lengths -------------------------------------------------------
        foreach (['title' => 'title_max', 'hook' => 'hook_max'] as $field => $limit) {
            if ($item[$field] === '') {
                $v[] = "{$field}_empty";
            } elseif (mb_strlen($item[$field]) > $ed[$limit]) {
                $v[] = "{$field}_too_long";
            }
        }
        if ($item['caption'] === '') {
            $v[] = 'caption_empty';
        }
        if (mb_strlen($this->finalCaption($item)) > $ed['caption_max']) {
            $v[] = 'caption_too_long';
        }

        // --- hashtags ------------------------------------------------------
        $count = count($item['hashtags']);
        if ($count < $ed['hashtags_min'] || $count > $ed['hashtags_max']) {
            $v[] = 'hashtags_count';
        }
        $lower = array_map('mb_strtolower', $item['hashtags']);
        if (count($lower) !== count(array_unique($lower))) {
            $v[] = 'hashtag_duplicate';
        }
        foreach ($item['hashtags'] as $tag) {
            if (! $this->rules->validHashtag($tag, $ed['hashtag_max_length'])) {
                $v[] = 'hashtag_format';
                break;
            }
        }

        // --- slides --------------------------------------------------------
        $slides = count($item['slides']);
        if ($slides < $ed['slides_min'] || $slides > $ed['slides_max']) {
            $v[] = 'slides_count';
        }
        foreach ($item['slides'] as $slide) {
            if ($slide['heading'] === '' || $slide['body'] === '') {
                $v[] = 'slide_empty';
                break;
            }
            if (mb_strlen($slide['heading']) > $ed['slide_heading_max'] || mb_strlen($slide['body']) > $ed['slide_body_max']) {
                $v[] = 'slide_too_long';
                break;
            }
        }

        // --- ids must come from what we offered --------------------------------
        $sourceIndex = $ctx->sourceIndex();
        $selected = [];
        foreach ($item['source_ids'] as $id) {
            if (isset($sourceIndex[$id])) {
                $selected[] = $sourceIndex[$id];
            } else {
                $v[] = 'source_unknown:'.$id;
            }
        }
        if ($slot === 'news' && $item['source_ids'] === []) {
            $v[] = 'source_missing';
        }

        $productIndex = $ctx->productIndex();
        $subjects = [];
        foreach ($item['product_ids'] as $id) {
            if ($slot !== 'equipment' || $ctx->equipmentMode !== 'product') {
                $v[] = 'product_not_allowed';
                break;
            }
            if (isset($productIndex[$id])) {
                $subjects[] = $productIndex[$id];
            } else {
                $v[] = 'product_unknown:'.$id;
            }
        }
        if ($slot === 'equipment' && $ctx->equipmentMode === 'product' && $item['product_ids'] === []) {
            $v[] = 'product_missing';
        }

        // --- forbidden claims ------------------------------------------------
        $text = $this->rules->viewerText($item['title'], $item['hook'], $item['caption'], $item['slides']);
        foreach ($this->rules->findPhrases($ed['forbidden_phrases'], $text) as $phrase) {
            $v[] = 'forbidden_phrase:'.$phrase;
        }
        $newsAllowed = $slot === 'news' && $selected !== []
            && max(array_column($selected, 'published_ts')) >= now()->subDay()->getTimestamp();
        if (! $newsAllowed) {
            foreach ($this->rules->findPhrases($ed['news_only_phrases'], $text) as $phrase) {
                $v[] = 'news_only_phrase:'.$phrase;
            }
        }

        // --- news: credit the publisher ----------------------------------------
        if ($slot === 'news' && $selected !== []) {
            $caption = mb_strtolower($item['caption']);
            $credited = str_contains($caption, 'nguồn:');
            $named = false;
            foreach ($selected as $source) {
                if ($source['publisher'] !== '' && str_contains($caption, mb_strtolower($source['publisher']))) {
                    $named = true;
                }
            }
            if (! $credited || ! $named) {
                $v[] = 'news_missing_credit';
            }
        }

        // --- equipment: no invented specs or prices -------------------------------
        if ($slot === 'equipment') {
            $allowed = [];
            $priceVisible = false;
            foreach ($subjects as $product) {
                $allowed = array_merge($allowed, $this->rules->specTokens(implode(' ', $product['facts'])));
                $priceVisible = $priceVisible || (bool) ($product['price']['visible'] ?? false);
            }
            foreach ($this->rules->specTokens($text) as $token) {
                if (! in_array($token, $allowed, true)) {
                    $v[] = 'spec_token_unverified:'.$token;
                }
            }
            if (! $priceVisible && $this->rules->hasCurrency($text)) {
                $v[] = 'price_not_allowed';
            }
        }

        // --- repeats ---------------------------------------------------------
        $threshold = (float) $ed['duplicate_similarity'];
        if ($item['title'] !== '' && $this->similarity->mostSimilar($item['title'], $ctx->recentTitles) >= $threshold) {
            $v[] = 'duplicate_title';
        }
        if ($item['title'] !== '' && $this->similarity->mostSimilar($item['title'], $acceptedTitles) >= $threshold) {
            $v[] = 'duplicate_in_plan';
        }

        return array_values(array_unique($v));
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private function normalize(array $raw): array
    {
        $str = fn ($v) => trim((string) ($v ?? ''));

        $hashtags = [];
        foreach ((array) ($raw['hashtags'] ?? []) as $tag) {
            $tag = preg_replace('/\s+/u', '', $str($tag)) ?? '';
            if ($tag === '') {
                continue;
            }
            $hashtags[] = str_starts_with($tag, '#') ? $tag : '#'.$tag;
        }

        $slides = [];
        foreach ((array) ($raw['slides'] ?? []) as $slide) {
            $slides[] = ['heading' => $str($slide['heading'] ?? ''), 'body' => $str($slide['body'] ?? '')];
        }

        return [
            'slot' => $str($raw['slot'] ?? ''),
            'format' => $str($raw['format'] ?? ''),
            'title' => $str($raw['title'] ?? ''),
            'hook' => $str($raw['hook'] ?? ''),
            'caption' => $this->rules->stripHashtags($str($raw['caption'] ?? '')),
            'hashtags' => $hashtags,
            'slides' => $slides,
            'source_ids' => array_values(array_filter(array_map($str, (array) ($raw['source_ids'] ?? [])))),
            'product_ids' => array_values(array_filter(array_map($str, (array) ($raw['product_ids'] ?? [])))),
            'viewer_question' => $str($raw['viewer_question'] ?? ''),
            // Nothing in the input proves a trend, so the model may not claim one.
            'demand_basis' => ($raw['demand_basis'] ?? '') === 'evergreen' ? 'evergreen' : 'hypothesis',
            'review_reason' => ($r = $str($raw['review_reason'] ?? '')) === '' ? null : $r,
        ];
    }

    /** The exact text that would be posted: caption + hashtags (+ disclosure). */
    public function finalCaption(array $item): string
    {
        $text = $item['caption']."\n\n".implode(' ', $item['hashtags']);
        if (config('content-studio.editorial.disclosure_required')) {
            $text .= ' '.config('content-studio.editorial.disclosure_text');
        }

        return trim($text);
    }
}
