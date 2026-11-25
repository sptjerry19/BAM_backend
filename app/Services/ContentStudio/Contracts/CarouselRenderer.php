<?php

namespace App\Services\ContentStudio\Contracts;

use App\Services\ContentStudio\Render\CarouselSpec;

interface CarouselRenderer
{
    /** Short name stored in the manifest: gd | playwright. */
    public function name(): string;

    /**
     * Draw every slide as a PNG of the size in the spec, named 01.png, 02.png...
     *
     * @return list<string> absolute file paths in slide order
     *
     * @throws \App\Services\ContentStudio\Render\RenderException
     */
    public function render(CarouselSpec $spec, string $outDir): array;
}
