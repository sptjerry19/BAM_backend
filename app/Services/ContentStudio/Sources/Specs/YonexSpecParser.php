<?php

namespace App\Services\ContentStudio\Sources\Specs;

use DOMDocument;
use DOMXPath;

final class YonexSpecParser implements SpecParser
{
    public function parse(string $html): ?array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xp = new DOMXPath($dom);
        $rows = $xp->query('//table[@id="product-attribute-specs-table"]//tr');
        if ($rows === false || $rows->length === 0) {
            return null;
        }

        $specs = [];
        foreach ($rows as $row) {
            $label = $xp->query('.//th', $row)->item(0)?->textContent;
            $cell = $xp->query('.//td', $row)->item(0);
            if ($label === null || $cell === null) {
                continue;
            }
            // <br> separates lines inside one cell; keep them readable
            foreach (iterator_to_array($xp->query('.//br', $cell)) as $br) {
                $br->parentNode?->replaceChild($dom->createTextNode(' / '), $br);
            }
            $value = self::clean($cell->textContent);
            $label = self::clean($label);
            if ($label !== '' && $value !== '' && mb_strlen($value) <= 200) {
                $specs[$label] = $value;
            }
        }

        // A racket page has weight/grip and a frame or shaft entry.
        $hasWeight = isset($specs['Weight / Grip']) || isset($specs['Weight']);
        if (! $hasWeight || (! isset($specs['Frame']) && ! isset($specs['Shaft']))) {
            return null;
        }

        $title = $xp->query('//h1')->item(0)?->textContent ?? $xp->query('//title')->item(0)?->textContent ?? '';
        $model = self::clean($title);

        return $model === '' ? null : ['model' => $model, 'specs' => $specs];
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }
}
