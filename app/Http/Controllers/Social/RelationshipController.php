<?php

namespace App\Http\Controllers\Social;

use App\Http\Requests\Social\BrowseRequest;
use App\Http\Requests\Social\RelationshipRequest;
use App\Http\Requests\Social\ReportRequest;
use App\Http\Requests\Social\ReportStatusRequest;
use App\Services\Social\RelationshipService;
use Illuminate\Http\JsonResponse;

class RelationshipController extends SocialController
{
    public function counts(): JsonResponse
    {
        return $this->success($this->service->counts());
    }

    public function __construct(private readonly RelationshipService $service) {}

    public function act(RelationshipRequest $request, int $user): JsonResponse
    {
        return $this->success($this->service->act($user, $request->validated()['action']));
    }

    public function people(BrowseRequest $request, int $user, string $type): JsonResponse
    {
        return $this->success($this->service->people($user, $type, $request->validated()));
    }

    public function search(BrowseRequest $request): JsonResponse
    {
        return $this->success($this->service->people(0, 'search', $request->validated()));
    }

    public function notifications(BrowseRequest $request): JsonResponse
    {
        return $this->success($this->service->notifications($request->validated()));
    }

    public function read(int $notification): JsonResponse
    {
        $this->service->readNotification($notification);

        return $this->success(null);
    }

    public function readAll(): JsonResponse
    {
        $this->service->readNotification(null);

        return $this->success(null);
    }

    public function report(ReportRequest $request): JsonResponse
    {
        $this->service->report($request->validated());

        return $this->success(null);
    }

    public function reports(BrowseRequest $request): JsonResponse
    {
        return $this->success($this->service->reports($request->validated()));
    }

    public function review(ReportStatusRequest $request, int $report): JsonResponse
    {
        $this->service->reviewReport($report, $request->validated()['status']);

        return $this->success(null);
    }
}
