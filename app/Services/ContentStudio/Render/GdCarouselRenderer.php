<?php

namespace App\Services\ContentStudio\Render;

use App\Services\ContentStudio\Contracts\CarouselRenderer;
use GdImage;

/**
 * Draws carousel slides with GD + FreeType: no browser, no extra container.
 * Layout respects TikTok's UI: text stays out of the top strip, the right
 * edge and the bottom ~350px that the app covers with caption and buttons.
 * Text is fitted by shrinking the font; if it still does not fit the slide is
 * an error (the plan validator limits lengths, this is the last line of defence).
 */
final class GdCarouselRenderer implements CarouselRenderer
{
    private const MARGIN_LEFT = 90;

    private const MARGIN_RIGHT = 140;   // TikTok action buttons live on the right edge

    private const HEADER_Y = 175;

    private const CONTENT_TOP = 300;

    private const CONTENT_BOTTOM = 1380;

    // Vietnamese stacks diacritics above and below the line: tight leading makes them collide.
    private const HEADING_LEADING = 1.38;

    private const BODY_LEADING = 1.5;

    private const FOOTER_Y = 1470;      // everything below ~1540 is covered by the app caption

    public function name(): string
    {
        return 'gd';
    }

    public function render(CarouselSpec $spec, string $outDir): array
    {
        $fonts = $this->fonts();
        if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
            throw new RenderException("Cannot create {$outDir}.");
        }

        $paths = [];
        foreach ($spec->slides as $slide) {
            $path = sprintf('%s/%02d.png', rtrim($outDir, '/\\'), $slide['index']);
            $this->drawSlide($spec, $slide, $fonts, $path);
            $paths[] = $path;
        }

        return $paths;
    }

    /** @return array{regular: string, bold: string} */
    private function fonts(): array
    {
        if (! function_exists('imagettftext')) {
            throw new RenderException('PHP GD was built without FreeType support.');
        }
        $fonts = (array) config('content-studio.render.fonts');
        foreach (['regular', 'bold'] as $weight) {
            if (empty($fonts[$weight]) || ! is_file($fonts[$weight])) {
                throw new RenderException("Font file for '{$weight}' is missing: ".($fonts[$weight] ?? '(not configured)'));
            }
        }

        return ['regular' => $fonts['regular'], 'bold' => $fonts['bold']];
    }

    /** @param array<string, mixed> $slide @param array{regular: string, bold: string} $fonts */
    private function drawSlide(CarouselSpec $spec, array $slide, array $fonts, string $path): void
    {
        $w = $spec->width;
        $h = $spec->height;
        $im = imagecreatetruecolor($w, $h);
        imageantialias($im, true);

        $this->gradient($im, $w, $h, $spec->theme['from'], $spec->theme['to']);
        $accent = $this->color($im, $spec->theme['accent']);
        $white = $this->color($im, '#FFFFFF');
        $soft = $this->color($im, '#E4E9F2');
        $muted = $this->color($im, '#B9C4D8');

        // header: brand + slot label (left), page counter (right)
        imagettftext($im, 30, 0, self::MARGIN_LEFT, self::HEADER_Y, $white, $fonts['bold'], $spec->brand);
        $brandWidth = $this->width($spec->brand, 30, $fonts['bold']);
        imagettftext($im, 26, 0, self::MARGIN_LEFT + $brandWidth + 28, self::HEADER_Y, $accent, $fonts['bold'], $spec->slotLabel);
        $counter = $slide['index'].'/'.$slide['total'];
        imagettftext($im, 28, 0, $w - self::MARGIN_RIGHT - $this->width($counter, 28, $fonts['regular']), self::HEADER_Y, $muted, $fonts['regular'], $counter);

        // text block, vertically centred inside the safe area
        $maxWidth = $w - self::MARGIN_LEFT - self::MARGIN_RIGHT;
        $layout = $this->fit($slide, $maxWidth, self::CONTENT_BOTTOM - self::CONTENT_TOP, $fonts);
        $y = self::CONTENT_TOP + (int) ((self::CONTENT_BOTTOM - self::CONTENT_TOP - $layout['height']) / 2);

        imagefilledrectangle($im, self::MARGIN_LEFT, $y - 36, self::MARGIN_LEFT + 96, $y - 28, $accent); // accent bar
        foreach ($layout['heading'] as $line) {
            imagettftext($im, $layout['heading_size'], 0, self::MARGIN_LEFT, (int) ($y + $layout['heading_size']), $white, $fonts['bold'], $line);
            $y += (int) round($layout['heading_size'] * self::HEADING_LEADING);
        }
        $y += 34;
        foreach ($layout['body'] as $line) {
            imagettftext($im, $layout['body_size'], 0, self::MARGIN_LEFT, (int) ($y + $layout['body_size']), $soft, $fonts['regular'], $line);
            $y += (int) round($layout['body_size'] * self::BODY_LEADING);
        }

        // footer: source credit (news) or the channel handle
        imagettftext($im, 28, 0, self::MARGIN_LEFT, self::FOOTER_Y, $muted, $fonts['regular'], $this->truncate($spec->footer, $maxWidth, 28, $fonts['regular']));

        if (! imagepng($im, $path, 6)) {
            throw new RenderException("Could not write {$path}.");
        }
        unset($im);
    }

    /**
     * Largest font that lets both blocks fit; shrinks heading and body together.
     *
     * @param  array<string, mixed>  $slide
     * @param  array{regular: string, bold: string}  $fonts
     * @return array{heading: list<string>, body: list<string>, heading_size: int, body_size: int, height: int}
     */
    private function fit(array $slide, int $maxWidth, int $available, array $fonts): array
    {
        $hook = $slide['kind'] === 'hook';
        $headingStart = $hook ? 86 : 66;
        $headingMin = $hook ? 54 : 44;
        $bodyStart = $hook ? 50 : 46;
        $bodyMin = 34;

        for ($scale = 1.0; $scale >= 0.55; $scale -= 0.03) {
            $hs = max($headingMin - 8, (int) round($headingStart * $scale));
            $bs = max($bodyMin - 6, (int) round($bodyStart * $scale));
            $heading = $this->wrap($slide['heading'], $hs, $fonts['bold'], $maxWidth);
            $body = $this->wrap($slide['body'], $bs, $fonts['regular'], $maxWidth);

            $height = count($heading) * (int) round($hs * self::HEADING_LEADING) + ($body === [] ? 0 : 34 + count($body) * (int) round($bs * self::BODY_LEADING));
            if ($height <= $available && count($heading) <= 5 && count($body) <= 9) {
                return ['heading' => $heading, 'body' => $body, 'heading_size' => $hs, 'body_size' => $bs, 'height' => $height];
            }
        }

        throw new RenderException("Text of slide {$slide['index']} does not fit the slide even at the smallest size.");
    }

    /** @return list<string> */
    private function wrap(string $text, int $size, string $font, int $maxWidth): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            foreach ($this->breakLongWord($word, $size, $font, $maxWidth) as $piece) {
                $candidate = $current === '' ? $piece : $current.' '.$piece;
                if ($this->width($candidate, $size, $font) <= $maxWidth) {
                    $current = $candidate;
                } else {
                    if ($current !== '') {
                        $lines[] = $current;
                    }
                    $current = $piece;
                }
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /** A single word wider than the box (a long URL, a typo) is split by characters. @return list<string> */
    private function breakLongWord(string $word, int $size, string $font, int $maxWidth): array
    {
        if ($this->width($word, $size, $font) <= $maxWidth) {
            return [$word];
        }
        $pieces = [];
        $buffer = '';
        foreach (mb_str_split($word) as $char) {
            if ($buffer !== '' && $this->width($buffer.$char, $size, $font) > $maxWidth) {
                $pieces[] = $buffer;
                $buffer = '';
            }
            $buffer .= $char;
        }

        return $buffer === '' ? $pieces : [...$pieces, $buffer];
    }

    private function truncate(string $text, int $maxWidth, int $size, string $font): string
    {
        while (mb_strlen($text) > 1 && $this->width($text, $size, $font) > $maxWidth) {
            $text = rtrim(mb_substr($text, 0, -2)).'…';
        }

        return $text;
    }

    private function width(string $text, int $size, string $font): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box === false ? 0 : abs($box[2] - $box[0]);
    }

    private function gradient(GdImage $im, int $w, int $h, string $from, string $to): void
    {
        [$r1, $g1, $b1] = $this->rgb($from);
        [$r2, $g2, $b2] = $this->rgb($to);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $color = imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imageline($im, 0, $y, $w, $y, $color);
        }
    }

    private function color(GdImage $im, string $hex): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return (int) imagecolorallocate($im, $r, $g, $b);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
