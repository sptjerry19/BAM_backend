<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\IndexMemberRequest;
use App\Http\Requests\Member\UpdateMemberStatusRequest;
use App\Services\Member\MemberService;
use App\Services\Social\ProfileService;
use Illuminate\Http\JsonResponse;

class MemberController extends Controller
{
    public function __construct(private readonly MemberService $members) {}

    public function index(IndexMemberRequest $request): JsonResponse
    {
        return $this->success($this->members->list($request->validated()));
    }

    public function show(string $id): JsonResponse
    {
        $viewer = auth('api')->user();
        if ($viewer?->member_id === (int) $id || $viewer?->isAdmin()) {
            return $this->success($this->members->findOrFail($id));
        }

        return $this->success(app(ProfileService::class)->member((int) $id));
    }

    public function updateStatus(UpdateMemberStatusRequest $request, string $id): JsonResponse
    {
        $member = $this->members->findOrFail($id);

        return $this->success(
            $this->members->updateStatus($member, $request->validated()['status']),
            __('messages.updated')
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->members->delete($this->members->findOrFail($id));

        return $this->success(null, __('messages.deleted'));
    }

    public function attachEquipment(string $member, string $product): JsonResponse
    {
        return $this->success(
            $this->members->attachEquipment($member, $product),
            __('messages.updated')
        );
    }

    public function detachEquipment(string $member, string $product): JsonResponse
    {
        return $this->success(
            $this->members->detachEquipment($member, $product),
            __('messages.updated')
        );
    }
}
