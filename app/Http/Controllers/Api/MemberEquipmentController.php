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
        $member = $this->equipments->listForMember($member);
        if ($member) {
            return $this->success($member);
        }
        return $this->error(__('messages.member_not_found'));
    }

    public function store(StoreMemberEquipmentRequest $request, string $member): JsonResponse
    {
        $params = $request->validated();

        $equipment = $this->equipments->create($member, $params);
        if ($equipment) {
        return $this->success(
                $equipment,
                __('messages.created'),
                201
            );
        }
        return $this->error(__('messages.failed_to_create_equipment'));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->equipments->delete($id);

        return $this->success(null, __('messages.deleted'));
    }
}
