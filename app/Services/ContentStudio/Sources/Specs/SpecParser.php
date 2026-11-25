<?php

namespace App\Services\ContentStudio\Sources\Specs;

/**
 * Reads the spec table of one manufacturer's product page. Returns null when the page is
 * not a racket with a spec table (shuttles, apparel, category pages ...). Values are copied
 * verbatim (whitespace-normalised), never converted or inferred.
 */
interface SpecParser
{
    /** @return array{model: string, specs: array<string, string>}|null */
    public function parse(string $html): ?array;
}
