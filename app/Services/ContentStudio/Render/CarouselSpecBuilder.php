<?php

namespace App\Services\ContentStudio\Render;

use App\Models\ContentStudio\ContentItem;

final class CarouselSpecBuilder
{
    private const THEMES = [
        'news' => ['label' => 'TIN TỨC', 'from' => '#0B1F3A', 'to' => '#1B4F8C', 'accent' => '#FFC93C'],
        'equipment' => ['label' => 'CHỌN TRANG BỊ', 'from' => '#0C2B24', 'to' => '#14705F', 'accent' => '#FFD166'],
        'tutorial' => ['label' => 'KỸ THUẬT', 'from' => '#1D1442', 'to' => '#4A3AA7', 'accent' => '#7EE0C3'],
        'meme' => ['label' => 'GÓC VUI', 'from' => '#3A1208', 'to' => '#B8431B', 'accent' => '#FFE08A'],
    ];

    /** @throws RenderException when the item has no usable slides */
    public function fromItem(ContentItem $item): CarouselSpec
    {
        $slot = $item->slot->value;
        $theme = self::THEMES[$slot];
        $raw = (array) ($item->script_json['slides'] ?? []);
        $total = count($raw);
        if ($total === 0) {
            throw new RenderException("Content item {$item->id} has no slides to render.");
        }

        $slides = [];
        foreach (array_values($raw) as $i => $slide) {
            $heading = $this->clean((string) ($slide['heading'] ?? ''));
            $body = $this->clean((string) ($slide['body'] ?? ''));
            if ($heading === '' && $body === '') {
                throw new RenderException("Slide ".($i + 1)." of item {$item->id} is empty.");
            }
            $slides[] = [
                'kind' => $i === 0 ? 'hook' : ($i === $total - 1 ? 'closing' : 'content'),
                'heading' => $heading,
                'body' => $body,
                'index' => $i + 1,
                'total' => $total,
            ];
        }

        return new CarouselSpec(
            slot: $slot,
            slotLabel: $theme['label'],
            brand: 'JERRY BADMINTON',
            theme: ['from' => $theme['from'], 'to' => $theme['to'], 'accent' => $theme['accent']],
            slides: $slides,
            footer: $this->footer($item),
            width: (int) config('content-studio.render.width'),
            height: (int) config('content-studio.render.height'),
        );
    }

    /** News carries its credit on every slide; everything else carries the handle. */
    private function footer(ContentItem $item): string
    {
        $handle = '@'.config('content-studio.channel.handle');
        if ($item->slot->value !== 'news') {
            return $handle;
        }

        $source = $item->sources()->orderBy('content_sources.id')->first();
        if ($source === null) {
            return $handle;
        }
        $date = $source->published_at?->setTimezone(config('content-studio.timezone'))->format('d/m/Y');

        return trim('Nguồn: '.$source->creator.($date ? ' · '.$date : ''));
    }

    /**
     * The fonts have no emoji glyphs (they would render as empty boxes), and slide
     * text must be plain: remove emoji and invisible formatting characters.
     */
    private function clean(string $text): string
    {
        $text = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200B}-\x{200F}\x{202A}-\x{202E}]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
