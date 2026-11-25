<?php

namespace App\Services\ContentStudio\Render;

use App\Services\ContentStudio\Contracts\CarouselRenderer;
use App\Services\ContentStudio\Ops\ContentEventLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Renders with headless Chromium inside the `content-media` container.
 * The Python script is run with an argv array (no shell), reads one JSON file
 * we wrote, and fills a fixed template through textContent only.
 */
final class PlaywrightCarouselRenderer implements CarouselRenderer
{
    public function __construct(private readonly ContentEventLogger $events) {}

    public function name(): string
    {
        return 'playwright';
    }

    public function render(CarouselSpec $spec, string $outDir): array
    {
        $script = (string) config('content-studio.render.script');
        if (! is_file($script)) {
            throw new RenderException("Render script not found: {$script}. The playwright driver only works inside the content-media image.");
        }
        if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
            throw new RenderException("Cannot create {$outDir}.");
        }

        $input = rtrim($outDir, '/\\').'/input.json';
        file_put_contents($input, json_encode($spec->toArray() + [
            'template' => base_path('resources/content-studio/templates/carousel.html'),
            'fonts' => (array) config('content-studio.render.fonts'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $process = new Process([(string) config('content-studio.render.python'), $script, '--input', $input, '--out', $outDir]);
        $process->setTimeout((int) config('content-studio.render.timeout_seconds'));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RenderException('Chromium render timed out after '.config('content-studio.render.timeout_seconds').'s.', retryable: true);
        } finally {
            @unlink($input);
        }

        if (! $process->isSuccessful()) {
            $error = $this->events->scrub(mb_substr(trim($process->getErrorOutput()), -500));

            // exit 2 = the text does not fit: deterministic, retrying cannot help
            throw new RenderException("Chromium render failed (exit {$process->getExitCode()}): {$error}", retryable: $process->getExitCode() !== 2);
        }

        $paths = [];
        foreach ($spec->slides as $slide) {
            $path = sprintf('%s/%02d.png', rtrim($outDir, '/\\'), $slide['index']);
            if (! is_file($path) || filesize($path) === 0) {
                throw new RenderException("Chromium reported success but slide {$slide['index']} is missing.");
            }
            $paths[] = $path;
        }

        return $paths;
    }
}
