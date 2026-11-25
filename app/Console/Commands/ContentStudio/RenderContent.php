<?php

namespace App\Console\Commands\ContentStudio;

use App\Console\Commands\ContentStudio\Concerns\FindsContentItem;
use App\Services\ContentStudio\Render\CarouselProducer;
use App\Services\ContentStudio\Render\RenderException;
use Illuminate\Console\Command;

class RenderContent extends Command
{
    use FindsContentItem;

    protected $signature = 'content:render {--content-id= : Content item id} {--force : Write a new version even if nothing changed}';

    protected $description = 'Render the carousel of one item right now (no queue, no status change). Handy for debugging.';

    public function handle(CarouselProducer $producer): int
    {
        if (($item = $this->contentItem()) === null) {
            return self::FAILURE;
        }

        try {
            $result = $producer->produce($item, (bool) $this->option('force'));
        } catch (RenderException $e) {
            $this->error('Render failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s v%d on disk "%s": %d slides, driver=%s',
            $result['reused'] ? 'Already rendered' : 'Rendered', $result['version'], $producer->disk(),
            count($result['manifest']['slides']), $result['manifest']['driver'],
        ));
        $this->line('manifest: '.$producer->manifestPath($item, $result['version']));

        return self::SUCCESS;
    }
}
