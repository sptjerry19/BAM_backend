<?php

namespace App\Http\Controllers\Social;

use App\Http\Requests\Social\UploadMediaRequest;
use App\Services\Social\MediaService;
use Illuminate\Http\Request;

class MediaController extends SocialController
{
    public function __construct(private readonly MediaService $service) {}

    public function store(UploadMediaRequest $request)
    {
        return $this->success($this->service->upload($request->file('file'), $request->validated()['purpose']), __('messages.created'), 201);
    }

    public function show(Request $request, string $media)
    {
        return $this->service->read($media, (int) $request->query('viewer', 0), $request->hasValidSignature(false), $request->boolean('poster'));
    }

    public function access(string $media)
    {
        return $this->success($this->service->access($media));
    }

    public function remove(string $purpose)
    {
        $this->service->removeProfileImage($purpose);

        return $this->success(null);
    }

    public function retry(string $media)
    {
        $this->service->retry($media);

        return $this->success(null);
    }
}
