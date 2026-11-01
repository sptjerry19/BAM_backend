<?php

namespace App\Http\Controllers\Social;

use App\Http\Requests\Social\BrowseRequest;
use App\Http\Requests\Social\HighlightRequest;
use App\Http\Requests\Social\ReactionRequest;
use App\Http\Requests\Social\StoryRequest;
use App\Services\Social\StoryService;
use Illuminate\Http\JsonResponse;

class StoryController extends SocialController
{
    public function __construct(private readonly StoryService $service) {}

    public function index(BrowseRequest $request, int $user): JsonResponse
    {
        return $this->success($this->service->list($user, $request->validated()['archive'] ?? false));
    }

    public function store(StoryRequest $request): JsonResponse
    {
        return $this->success($this->service->create($request->validated()));
    }

    public function view(int $story): JsonResponse
    {
        $this->service->view($story);

        return $this->success(null);
    }

    public function react(ReactionRequest $request, int $story): JsonResponse
    {
        $this->service->view($story, $request->validated()['reaction']);

        return $this->success(null);
    }

    public function views(int $story): JsonResponse
    {
        return $this->success($this->service->views($story));
    }

    public function destroy(int $story): JsonResponse
    {
        $this->service->delete($story);

        return $this->success(null);
    }

    public function highlights(int $user): JsonResponse
    {
        return $this->success($this->service->highlights($user));
    }

    public function highlight(HighlightRequest $request): JsonResponse
    {
        return $this->success($this->service->highlight($request->validated()));
    }

    public function updateHighlight(HighlightRequest $request, int $highlight): JsonResponse
    {
        return $this->success($this->service->highlight($request->validated(), $highlight));
    }

    public function deleteHighlight(int $highlight): JsonResponse
    {
        $this->service->deleteHighlight($highlight);

        return $this->success(null);
    }
}
