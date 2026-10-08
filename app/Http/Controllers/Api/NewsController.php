<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\News\IndexNewsRequest;
use App\Http\Requests\News\StoreNewsRequest;
use App\Http\Requests\News\UpdateNewsRequest;
use App\Services\News\NewsService;
use Illuminate\Http\JsonResponse;

class NewsController extends Controller
{
    public function __construct(private readonly NewsService $news)
    {
    }

    public function index(IndexNewsRequest $request): JsonResponse
    {
        return $this->success($this->news->list($request->validated()));
    }

    public function store(StoreNewsRequest $request): JsonResponse
    {
        return $this->success(
            $this->news->create($request->validated()),
            __('messages.created'),
            201
        );
    }

    public function show(string $id): JsonResponse
    {
        return $this->success($this->news->findOrFail($id));
    }

    public function update(UpdateNewsRequest $request, string $id): JsonResponse
    {
        $item = $this->news->findOrFail($id);

        return $this->success(
            $this->news->update($item, $request->validated()),
            __('messages.updated')
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->news->delete($this->news->findOrFail($id));

        return $this->success(null, __('messages.deleted'));
    }
}
