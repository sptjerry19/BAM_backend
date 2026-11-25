<?php

namespace App\Console\Commands\ContentStudio;

use App\Console\Commands\ContentStudio\Concerns\FindsContentItem;
use App\Services\ContentStudio\Publishers\ManualExportPublisher;
use Illuminate\Console\Command;
use RuntimeException;

class ExportContent extends Command
{
    use FindsContentItem;

    protected $signature = 'content:export {--content-id= : Content item id}';

    protected $description = 'Write the manual-upload package (slides, caption, qa.json, report.json) of a READY item. Posts nothing.';

    public function handle(ManualExportPublisher $exporter): int
    {
        if (($item = $this->contentItem()) === null) {
            return self::FAILURE;
        }

        try {
            $result = $exporter->export($item);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Package written: '.$result['directory'].' ('.count($result['files']).' files). Nothing has been posted or scheduled.');

        return self::SUCCESS;
    }
}
