<?php

namespace App\Http\Controllers\Social;

use App\Http\Requests\Social\BrowseRequest;
use App\Http\Requests\Social\PrivacyRequest;
use App\Http\Requests\Social\ProfileUpdateRequest;
use App\Services\Social\ProfileService;
use Illuminate\Http\JsonResponse;

class ProfileController extends SocialController
{
    public function __construct(private readonly ProfileService $service) {}

    public function show(int $user): JsonResponse
    {
        return $this->success($this->service->show($user));
    }

    public function member(int $member): JsonResponse
    {
        return $this->success($this->service->member($member));
    }

    public function memberEquipment(BrowseRequest $request, int $member): JsonResponse
    {
        return $this->success($this->service->memberEquipment($member, $request->validated()));
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        return $this->success($this->service->update($request->validated()));
    }

    public function privacy(PrivacyRequest $request): JsonResponse
    {
        return $this->success($this->service->privacy($request->validated()));
    }

    public function equipment(BrowseRequest $request, int $user): JsonResponse
    {
        return $this->success($this->service->equipment($user, $request->validated()));
    }
}
