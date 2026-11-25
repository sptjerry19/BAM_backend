<?php

namespace App\Services\ContentStudio\Render;

/**
 * Everything a renderer needs, as plain data. Renderers never see the model
 * or any HTML coming from the AI: text only, drawn by fixed templates.
 */
final class CarouselSpec
{
    /**
     * @param  array{from: string, to: string, accent: string}  $theme
     * @param  list<array{kind: string, heading: string, body: string, index: int, total: int}>  $slides
     */
    public function __construct(
        public readonly string $slot,
        public readonly string $slotLabel,
        public readonly string $brand,
        public readonly array $theme,
        public readonly array $slides,
        public readonly string $footer,
        public readonly int $width,
        public readonly int $height,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slot' => $this->slot, 'slot_label' => $this->slotLabel, 'brand' => $this->brand, 'theme' => $this->theme,
            'slides' => $this->slides, 'footer' => $this->footer, 'width' => $this->width, 'height' => $this->height,
        ];
    }

    /** Stable fingerprint of what will be drawn: lets a render be recognised as already done. */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
