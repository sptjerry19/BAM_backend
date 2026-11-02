<?php

namespace App\Http\Controllers\Social;

use App\Http\Requests\Social\BrowseRequest;
use App\Http\Requests\Social\ConversationRequest;
use App\Http\Requests\Social\MessageRequest;
use App\Http\Requests\Social\ReadMessageRequest;
use App\Services\Social\ChatService;
use Illuminate\Http\JsonResponse;

class ChatController extends SocialController
{
    public function __construct(private readonly ChatService $service) {}

    public function index(BrowseRequest $request): JsonResponse
    {
        return $this->success($this->service->conversations($request->validated()));
    }

    public function store(ConversationRequest $request): JsonResponse
    {
        return $this->success($this->service->start($request->validated()['user_id']));
    }

    public function messages(BrowseRequest $request, int $conversation): JsonResponse
    {
        return $this->success($this->service->messages($conversation, $request->validated()));
    }

    public function send(MessageRequest $request, int $conversation): JsonResponse
    {
        return $this->success($this->service->send($conversation, $request->validated()));
    }

    public function accept(int $conversation): JsonResponse
    {
        $this->service->accept($conversation);

        return $this->success(null);
    }

    public function read(ReadMessageRequest $request, int $conversation): JsonResponse
    {
        $this->service->read($conversation, $request->validated()['message_id']);

        return $this->success(null);
    }
}
