<?php

namespace App\Services\ContentStudio\Quality;

/**
 * Text checks shared by the plan validator (before rendering) and the quality
 * gate (on the final text). Matching is accent-SENSITIVE on purpose: stripping
 * Vietnamese diacritics would turn the common word "chưa" into "chua" and
 * block harmless text.
 */
final class TextRules
{
    /**
     * Measurements the model could invent (weight, length, balance point...).
     * "3U" / "4U" are NOT matched: they are the weight-class names every beginner
     * asks about, not a measurement. The gram range behind them is.
     */
    private const SPEC = '/(?<![\p{L}\p{N}])(\d{1,4}(?:[.,]\d+)?)\s?(kg|gram|lbs|lb|mm|cm|g)(?![\p{L}\p{N}])/iu';

    private const CURRENCY = '/\d[\d.,]*\s?(?:nghìn|triệu|vnđ|vnd|₫|k|tr|đ)(?![\p{L}\p{N}])/iu';

    /** @param list<string> $phrases @return list<string> phrases found in $text */
    public function findPhrases(array $phrases, string $text): array
    {
        $haystack = mb_strtolower($text);
        $found = [];
        foreach ($phrases as $phrase) {
            $needle = mb_strtolower($phrase);
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1) {
                $found[] = $phrase;
            }
        }

        return $found;
    }

    /** @return list<string> e.g. ["85g", "3u"] (lower-case, no spaces) */
    public function specTokens(string $text): array
    {
        if (preg_match_all(self::SPEC, $text, $m, PREG_SET_ORDER) === 0) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (array $x) => str_replace(',', '.', $x[1]).mb_strtolower($x[2]),
            $m,
        )));
    }

    public function hasCurrency(string $text): bool
    {
        return preg_match(self::CURRENCY, $text) === 1;
    }

    public function validHashtag(string $tag, int $maxLength): bool
    {
        return mb_strlen($tag) <= $maxLength && preg_match('/^#[\p{L}\p{N}_]+$/u', $tag) === 1;
    }

    /** Hashtags typed into free text are not allowed there; they live in their own field. */
    public function stripHashtags(string $text): string
    {
        return trim(preg_replace('/\s*#[\p{L}\p{N}_]+/u', '', $text) ?? $text);
    }

    /** Everything a viewer will read, flattened for scanning. */
    public function viewerText(string $title, string $hook, string $caption, array $slides): string
    {
        $parts = [$title, $hook, $caption];
        foreach ($slides as $slide) {
            $parts[] = (string) ($slide['heading'] ?? '');
            $parts[] = (string) ($slide['body'] ?? '');
        }

        return implode("\n", $parts);
    }
}
