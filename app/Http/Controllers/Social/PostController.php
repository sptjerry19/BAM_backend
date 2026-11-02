<?php

namespace App\Http\Controllers\Social;

use App\Http\Requests\Social\BrowseRequest;
use App\Http\Requests\Social\CommentRequest;
use App\Http\Requests\Social\PostRequest;
use App\Http\Requests\Social\ReactionRequest;
use App\Services\Social\PostService;
use App\Services\Social\VisibilityService;
use Illuminate\Http\JsonResponse;

class PostController extends SocialController
{
    public function __construct(private readonly PostService $service) {}

    public function index(BrowseRequest $request, int $user): JsonResponse
    {
        return $this->success($this->service->list($user, $request->validated()));
    }

    public function saved(BrowseRequest $request): JsonResponse
    {
        return $this->success($this->service->list(auth('api')->id(), $request->validated(), true));
    }

    public function show(int $post): JsonResponse
    {
        return $this->success($this->service->resources([$this->service->find($post)], app(VisibilityService::class)->viewer()?->id)[0]);
    }

    public function store(PostRequest $request): JsonResponse
    {
        return $this->success($this->service->create($request->validated()));
    }

    public function update(PostRequest $request, int $post): JsonResponse
    {
        return $this->success($this->service->update($post, $request->validated()));
    }

    public function destroy(int $post): JsonResponse
    {
        $this->service->delete($post);

        return $this->success(null);
    }

    public function restore(int $post): JsonResponse
    {
        $this->service->restore($post);

        return $this->success(null);
    }

    public function pin(int $post): JsonResponse
    {
        $this->service->pin($post, true);

        return $this->success(null);
    }

    public function unpin(int $post): JsonResponse
    {
        $this->service->pin($post, false);

        return $this->success(null);
    }

    public function react(ReactionRequest $request, int $post): JsonResponse
    {
        $this->service->reaction($post, $request->validated()['reaction']);

        return $this->success(null);
    }

    public function unreact(int $post): JsonResponse
    {
        $this->service->reaction($post, null);

        return $this->success(null);
    }

    public function save(int $post): JsonResponse
    {
        $this->service->save($post, true);

        return $this->success(null);
    }

    public function unsave(int $post): JsonResponse
    {
        $this->service->save($post, false);

        return $this->success(null);
    }

    public function comments(BrowseRequest $request, int $post): JsonResponse
    {
        return $this->success($this->service->comments($post, $request->validated()));
    }

    public function comment(CommentRequest $request, int $post): JsonResponse
    {
        return $this->success($this->service->comment($post, $request->validated()));
    }

    public function updateComment(CommentRequest $request, int $comment): JsonResponse
    {
        $this->service->modifyComment($comment, $request->validated());

        return $this->success(null);
    }

    public function deleteComment(int $comment): JsonResponse
    {
        $this->service->modifyComment($comment, null);

        return $this->success(null);
    }
}
