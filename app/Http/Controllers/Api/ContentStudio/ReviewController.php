<?php

namespace App\Http\Controllers\Api\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContentStudio\ContentItem;
use App\Services\ContentStudio\Ops\AutomationGate;
use App\Services\ContentStudio\Quality\QualityGate;
use App\Services\ContentStudio\Quality\QualityReport;
use App\Services\ContentStudio\Render\CarouselProducer;
use App\Services\ContentStudio\Review\ContentReview;
use App\Services\ContentStudio\Review\ReviewException;
use App\Services\ContentStudio\State\InvalidContentTransition;
use App\Services\ContentStudio\State\StaleContentItem;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything the review screen needs, and the three decisions a reviewer can take.
 * Approve and cancel carry the `lock_version` the reviewer saw: a decision about an
 * item that changed in the meantime is refused with 409.
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ContentReview $review,
        private readonly QualityGate $gate,
        private readonly CarouselProducer $producer,
    ) {}

    public function show(ContentItem $item): JsonResponse
    {
        $report = $this->gate->evaluate($item);
        $version = $this->producer->currentVersion($item);
        $manifest = $this->producer->readManifest($item, $version);
        $products = $item->products()->get();

        return ApiResponse::success([
            'item' => [
                'id' => $item->id,
                'slot' => $item->slot->value,
                'status' => $item->status->value,
                'lock_version' => $item->lock_version,
                'title' => $item->title,
                'hook' => $item->hook,
                // exactly what would be posted: caption + hashtags (+ disclosure)
                'final_caption' => $this->gate->finalCaption($item),
                'blocked_reason' => $item->blocked_reason,
                'proposed_publish_at' => $item->brief_json['proposed_publish_at'] ?? null,
                'mock' => (bool) ($item->brief_json['mock'] ?? false),
                'review_reason' => $item->brief_json['review_reason'] ?? null,
            ],
            'slides' => array_values((array) ($item->script_json['slides'] ?? [])),
            'render' => [
                'version' => $version,
                'images' => $manifest === null ? [] : array_column($manifest['slides'], 'index'),
            ],
            'qa' => $report->toArray(),
            'sources' => $item->sources()->get()->map(fn ($s) => [
                'id' => $s->id, 'url' => $s->canonical_url, 'creator' => $s->creator, 'title' => $s->title,
                'published_at' => $s->published_at?->toIso8601String(), 'rights_status' => $s->rights_status->value,
            ])->values(),
            'products' => $products->map(fn ($p) => [
                'product_id' => $p->product_id,
                'name' => $p->facts_snapshot_json['name'] ?? null,
                'facts' => $p->facts_snapshot_json['facts'] ?? [],
                'shop_url' => $p->facts_snapshot_json['source_url'] ?? null,
                'spec_url' => $p->facts_snapshot_json['spec_source_url'] ?? null,
                'price' => $p->price_snapshot_json,
            ])->values(),
            'actions' => $this->actions($item, $report),
        ]);
    }

    /** One rendered slide as PNG. The path comes from the manifest, never from the request. */
    public function slide(ContentItem $item, int $index): Response
    {
        $manifest = $this->producer->readManifest($item, $this->producer->currentVersion($item));
        $slide = collect($manifest['slides'] ?? [])->firstWhere('index', $index);
        if ($slide === null) {
            return ApiResponse::error('No such rendered slide.', 404);
        }

        return response((string) Storage::disk($this->producer->disk())->get($slide['path']), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function approve(Request $request, ContentItem $item): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'render_version' => ['required', 'integer'],
            'accepted' => ['present', 'array'],
            'accepted.*' => ['string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ((int) $data['lock_version'] !== $item->lock_version || (int) $data['render_version'] !== $this->producer->currentVersion($item)) {
            return $this->changed();
        }

        return $this->decide(function () use ($item, $data) {
            $result = $this->review->approve($item, (string) ($data['reason'] ?? ''), $this->actor(), $data['accepted']);

            return ApiResponse::success(
                ['status' => ContentStatus::Ready->value, 'export_directory' => $result['directory'], 'export_error' => $result['export_error']],
                $result['export_error'] === null
                    ? 'Approved. The export package is ready. Nothing has been posted.'
                    : 'Approved, but the export failed.',
            );
        });
    }

    public function retry(ContentItem $item, AutomationGate $automation): JsonResponse
    {
        return $this->decide(function () use ($item, $automation) {
            $run = $this->review->retry($item, $this->actor());

            return ApiResponse::success(
                ['run_id' => $run->id, 'paused' => $automation->isPaused()],
                $automation->isPaused()
                    ? 'Queued. Automation is paused, so it starts after you resume it.'
                    : 'The item is being produced again.',
            );
        });
    }

    public function cancel(Request $request, ContentItem $item): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        if ((int) $data['lock_version'] !== $item->lock_version) {
            return $this->changed();
        }

        return $this->decide(function () use ($item, $data) {
            $this->review->cancel($item, $data['reason'], $this->actor());

            return ApiResponse::success(['status' => ContentStatus::Cancelled->value], 'The item was cancelled.');
        });
    }

    /** @return array<string, array{available: bool, reason: ?string}> */
    private function actions(ContentItem $item, QualityReport $report): array
    {
        try {
            $this->review->assertApprovable($item, $report);
            $approve = ['available' => true, 'reason' => null];
        } catch (ReviewException $e) {
            $approve = ['available' => false, 'reason' => $e->reasonCode];
        }

        return [
            'approve' => $approve,
            'retry' => ['available' => $item->status === ContentStatus::Failed, 'reason' => null],
            'cancel' => ['available' => $this->review->canCancel($item), 'reason' => null],
        ];
    }

    private function decide(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ReviewException $e) {
            $status = $e->reasonCode === ReviewException::FINDINGS_CHANGED ? 409 : 422;

            return ApiResponse::error($e->getMessage(), $status, data: ['code' => $e->reasonCode, 'findings' => $e->findings]);
        } catch (StaleContentItem|InvalidContentTransition) {
            return $this->changed();
        }
    }

    private function changed(): JsonResponse
    {
        return ApiResponse::error('The item changed in the meantime. Reload it and decide again.', 409, data: ['code' => 'item_changed']);
    }

    private function actor(): string
    {
        return 'admin:'.auth('api')->id();
    }
}
