<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Upload\StoreUploadRequest;
use App\Services\Storage\UploadService;
use Illuminate\Http\JsonResponse;

class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploads)
    {
    }

    public function store(StoreUploadRequest $request): JsonResponse
    {
        return $this->success(
            $this->uploads->store($request->validated(), $request),
            __('messages.uploaded'),
            201
        );
    }
}
