<?php

namespace App\Services\ContentStudio\Sources\Specs;

use DOMDocument;

/** victorsport.com is a Next.js site: the product (incl. its spec table, as HTML) sits in __NEXT_DATA__. */
final class VictorSpecParser implements SpecParser
{
    public function parse(string $html): ?array
    {
        if (preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m) !== 1) {
            return null;
        }
        $json = json_decode($m[1], true);
        $product = $json['props']['pageProps']['product']['product'] ?? null;
        if (! is_array($product)) {
            return null;
        }

        $name = trim((string) ($product['name'] ?? ''));
        $table = (string) ($product['infoContent']['wysiwygContent'] ?? '');
        if ($name === '' || ! str_contains($table, '<table')) {
            return null;
        }

        $specs = $this->readTable($table);
        // Rackets list weight/grip together with frame and shaft material.
        if (! isset($specs['Weight / Grip Size']) || (! isset($specs['Frame Material']) && ! isset($specs['Shaft Material']))) {
            return null;
        }

        return ['model' => $name, 'specs' => $specs];
    }

    /** @return array<string, string> */
    private function readTable(string $fragment): array
    {
        // line breaks inside a cell must stay visible, or "G5" + "4U" fuses into "G54U"
        $fragment = preg_replace('#</?br\s*/?>|</p>#i', ' / ', $fragment) ?? $fragment;

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><body>'.$fragment.'</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $specs = [];
        foreach ($dom->getElementsByTagName('tr') as $row) {
            $cells = [];
            foreach ($row->childNodes as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE && in_array($c->nodeName, ['td', 'th'], true)) {
                    $cells[] = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $c->textContent) ?? '');
                }
            }
            if (count($cells) === 2 && $cells[0] !== '' && $cells[1] !== '' && mb_strlen($cells[1]) <= 200) {
                $specs[$cells[0]] = $cells[1];
            }
        }

        return $specs;
    }
}
