<?php

namespace App\Services\ContentStudio\Pipeline;

use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentSource;
use App\Services\ContentStudio\Publishers\ManualExportPublisher;
use App\Services\ContentStudio\Quality\QualityGate;
use App\Services\ContentStudio\Quality\QualityReport;
use App\Services\ContentStudio\Render\CarouselProducer;
use App\Services\ContentStudio\Render\RenderException;
use Illuminate\Support\Facades\Storage;

/**
 * The four steps of one carousel. Each method is idempotent (safe to run again
 * after a crash) and returns a small array that StepRunner stores as the
 * step's output. Moving the item between statuses is the job's concern.
 */
final class CarouselPipeline
{
    public function __construct(
        private readonly CarouselProducer $producer,
        private readonly QualityGate $gate,
        private readonly ManualExportPublisher $exporter,
    ) {}

    /** Can this item be produced at all? @return array{verdict: string, reason: ?string} */
    public function verify(ContentItem $item): array
    {
        if (empty($item->script_json['slides'])) {
            return ['verdict' => 'blocked', 'reason' => 'the item has no slides'];
        }

        if ($item->slot->value === 'news') {
            $retention = now()->subDays((int) config('content-studio.news.retention_days'));
            $valid = $item->sources()->get()->filter(fn (ContentSource $s) => $s->bucket === 'news'
                && $s->trust === 'official' && $s->published_at !== null && $s->published_at->greaterThanOrEqualTo($retention));

            if ($valid->isEmpty()) {
                return ['verdict' => 'blocked', 'reason' => 'news_source_invalid: no official, recent source is linked to this news post'];
            }
        }

        return ['verdict' => 'ok', 'reason' => null];
    }

    /** Where the render step will put its completion marker (declared up front for crash recovery). @return array{disk: string, path: string} */
    public function expectedRenderOutput(ContentItem $item): array
    {
        return ['disk' => $this->producer->disk(), 'path' => $this->producer->manifestPath($item, $this->producer->currentVersion($item))];
    }

    /**
     * @return array{version: int, slides: int, reused: bool, manifest: string}
     *
     * @throws NonRetryableStepException when the data can never render (retrying would be pointless)
     */
    public function render(ContentItem $item): array
    {
        try {
            $result = $this->producer->produce($item);
        } catch (RenderException $e) {
            throw $e->retryable ? $e : new NonRetryableStepException($e->getMessage(), 0, $e);
        }

        return [
            'version' => $result['version'],
            'slides' => count($result['manifest']['slides']),
            'reused' => $result['reused'],
            'manifest' => $this->producer->manifestPath($item, $result['version']),
        ];
    }

    /** @return array{verdict: string, failed: list<string>, summary: string, qa_path: string} */
    public function quality(ContentItem $item): array
    {
        $report = $this->gate->evaluate($item);
        $version = $this->producer->currentVersion($item);
        $qaPath = $this->producer->directory($item, $version).'/qa.json';
        Storage::disk($this->producer->disk())->put($qaPath, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'verdict' => $report->verdict(),
            'failed' => array_column($report->failed(), 'code'),
            'summary' => $report->summary(),
            'qa_path' => $qaPath,
        ];
    }

    /** @return array{directory: string, files: int} */
    public function export(ContentItem $item): array
    {
        $result = $this->exporter->export($item);

        return ['directory' => $result['directory'], 'files' => count($result['files'])];
    }

    public function verdictToStatus(string $verdict): string
    {
        return match ($verdict) {
            QualityReport::READY => 'ready',
            QualityReport::NEEDS_REVIEW => 'needs_review',
            default => 'blocked',
        };
    }
}
