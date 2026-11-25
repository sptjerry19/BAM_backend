<?php

namespace App\Services\ContentStudio\Planning;

/** Cheap near-duplicate detection for titles (token overlap, 0..1). */
final class TitleSimilarity
{
    public function normalize(string $title): string
    {
        $title = mb_strtolower($title);
        $title = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title) ?? $title;

        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    public function score(string $a, string $b): float
    {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);
        if ($ta === [] || $tb === []) {
            return 0.0;
        }
        $shared = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));

        return $union === 0 ? 0.0 : $shared / $union;
    }

    /** @param iterable<string> $others */
    public function mostSimilar(string $title, iterable $others): float
    {
        $best = 0.0;
        foreach ($others as $other) {
            $best = max($best, $this->score($title, (string) $other));
        }

        return $best;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $words = array_filter(explode(' ', $this->normalize($text)), fn ($w) => mb_strlen($w) >= 2);

        return array_values(array_unique($words));
    }
}
