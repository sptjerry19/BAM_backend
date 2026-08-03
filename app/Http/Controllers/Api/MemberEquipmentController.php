<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberEquipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MemberEquipmentController extends Controller
{
    private const CATEGORIES = 'racket,shoes,shirt,shorts,bag,grip,other,shuttlecock,string';

    public function index(string $member): JsonResponse
    {
        Member::findOrFail($member);

        $equipments = MemberEquipment::query()
            ->where('member_id', $member)
            ->orderByDesc('created_at')
            ->get();

        return $this->success($equipments);
    }

    public function store(Request $request, string $member): JsonResponse
    {
        Member::findOrFail($member);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'brand' => 'nullable|string|max:255',
            'category' => 'required|in:'.self::CATEGORIES,
            'price' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'image' => 'nullable|string',
        ]);

        $equipment = MemberEquipment::create([
            ...$validated,
            'id' => 'custom_'.Str::uuid(),
            'member_id' => $member,
        ]);

        return $this->success($equipment, __('messages.created'), 201);
    }

    public function destroy(string $id): JsonResponse
    {
        MemberEquipment::findOrFail($id)->delete();

        return $this->success(null, __('messages.deleted'));
    }
}
