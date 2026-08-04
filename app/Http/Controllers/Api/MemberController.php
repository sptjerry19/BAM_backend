<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\IndexMemberRequest;
use App\Services\Member\MemberService;
use Illuminate\Http\JsonResponse;

class MemberController extends Controller
{
    public function __construct(private readonly MemberService $members)
    {
    }

    public function index(IndexMemberRequest $request): JsonResponse
    {
        return $this->success($this->members->list($request->validated()));
    }

    public function show(string $id): JsonResponse
    {
        return $this->success($this->members->findOrFail($id));
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
