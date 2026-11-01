<?php

namespace App\Http\Controllers\Social;

use App\Http\Requests\Social\AlbumRequest;
use App\Http\Requests\Social\BrowseRequest;
use App\Http\Requests\Social\TagRequest;
use App\Http\Requests\Social\TagReviewRequest;
use App\Services\Social\LibraryService;
use Illuminate\Http\JsonResponse;

class LibraryController extends SocialController
{
    public function __construct(private readonly LibraryService $service) {}

    public function photos(BrowseRequest $request, int $user): JsonResponse
    {
        return $this->success($this->service->photos($user, $request->validated()));
    }

    public function albums(int $user): JsonResponse
    {
        return $this->success($this->service->albums($user));
    }

    public function album(int $album): JsonResponse
    {
        return $this->success($this->service->albumContents($album));
    }

    public function storeAlbum(AlbumRequest $request): JsonResponse
    {
        return $this->success($this->service->album($request->validated()));
    }

    public function updateAlbum(AlbumRequest $request, int $album): JsonResponse
    {
        return $this->success($this->service->album($request->validated(), $album));
    }

    public function deleteAlbum(int $album): JsonResponse
    {
        $this->service->deleteAlbum($album);

        return $this->success(null);
    }

    public function tag(TagRequest $request, int $post): JsonResponse
    {
        $this->service->tag($post, $request->validated()['user_id']);

        return $this->success(null);
    }

    public function reviewTag(TagReviewRequest $request, int $tag): JsonResponse
    {
        $this->service->reviewTag($tag, $request->validated()['status']);

        return $this->success(null);
    }

    public function photoTag(TagRequest $request, string $media): JsonResponse
    {
        $this->service->photoTag($media, $request->validated()['user_id']);

        return $this->success(null);
    }

    public function reviewPhotoTag(TagReviewRequest $request, int $tag): JsonResponse
    {
        $this->service->reviewPhotoTag($tag, $request->validated()['status']);

        return $this->success(null);
    }

    public function tagged(BrowseRequest $request, int $user): JsonResponse
    {
        return $this->success($this->service->tagged($user, $request->validated()));
    }

    public function activity(BrowseRequest $request): JsonResponse
    {
        return $this->success($this->service->activity($request->validated()));
    }
}
