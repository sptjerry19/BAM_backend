<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MemberEquipment\StoreMemberEquipmentRequest;
use App\Services\Member\MemberEquipmentService;
use Illuminate\Http\JsonResponse;

class MemberEquipmentController extends Controller
{
    public function __construct(private readonly MemberEquipmentService $equipments)
    {
    }

    public function index(string $member): JsonResponse
    {
        return $this->success($this->equipments->listForMember($member));
    }

    public function store(StoreMemberEquipmentRequest $request, string $member): JsonResponse
    {
        return $this->success(
            $this->equipments->create($member, $request->validated()),
            __('messages.created'),
            201
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->equipments->delete($id);

        return $this->success(null, __('messages.deleted'));
    }
}
