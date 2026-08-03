<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Member::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }

        if ($request->filled('per_page')) {
            return $this->success($query->paginate((int) $request->integer('per_page', 15)));
        }

        return $this->success($query->get());
    }

    public function show(string $id): JsonResponse
    {
        return $this->success(Member::findOrFail($id));
    }

    public function attachEquipment(string $member, string $product): JsonResponse
    {
        $memberModel = Member::findOrFail($member);
        Product::findOrFail($product);

        $equipment = $memberModel->equipment ?? [];

        if (! in_array($product, $equipment, true)) {
            $equipment[] = $product;
            $memberModel->update(['equipment' => $equipment]);
        }

        return $this->success($memberModel->fresh(), __('messages.updated'));
    }

    public function detachEquipment(string $member, string $product): JsonResponse
    {
        $memberModel = Member::findOrFail($member);

        $equipment = array_values(array_filter(
            $memberModel->equipment ?? [],
            fn (string $id) => $id !== $product
        ));

        $memberModel->update(['equipment' => $equipment]);

        return $this->success($memberModel->fresh(), __('messages.updated'));
    }
}
