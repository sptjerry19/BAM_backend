<?php

namespace App\Services\Member;

use App\Models\Member;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class MemberService
{
    /**
     * @return Collection<int, Member>|LengthAwarePaginator
     */
    public function list(array $filters): Collection|LengthAwarePaginator
    {
        $query = Member::query();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['level'])) {
            $query->where('level', $filters['level']);
        }

        if (! empty($filters['per_page'])) {
            return $query->paginate((int) $filters['per_page']);
        }

        return $query->get();
    }

    public function findOrFail(string $id): Member
    {
        return Member::findOrFail($id);
    }

    public function attachEquipment(string $memberId, string $productId): Member
    {
        $member = Member::findOrFail($memberId);
        Product::findOrFail($productId);

        $equipment = $member->equipment ?? [];

        if (! in_array($productId, $equipment, true)) {
            $equipment[] = $productId;
            $member->update(['equipment' => $equipment]);
        }

        return $member->fresh();
    }

    public function detachEquipment(string $memberId, string $productId): Member
    {
        $member = Member::findOrFail($memberId);

        $equipment = array_values(array_filter(
            $member->equipment ?? [],
            fn (string $id) => $id !== $productId
        ));

        $member->update(['equipment' => $equipment]);

        return $member->fresh();
    }
}
