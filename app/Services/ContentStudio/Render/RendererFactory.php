<?php

namespace App\Services\ContentStudio\Render;

use App\Services\ContentStudio\Contracts\CarouselRenderer;

final class RendererFactory
{
    public function make(?string $driver = null): CarouselRenderer
    {
        $driver ??= (string) config('content-studio.render.driver');

        return match ($driver) {
            'gd' => app(GdCarouselRenderer::class),
            'playwright' => app(PlaywrightCarouselRenderer::class),
            default => throw new RenderException("Unknown render driver '{$driver}' (use gd or playwright)."),
        };
    }
}
