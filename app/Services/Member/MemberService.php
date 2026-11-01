<?php

namespace App\Services\Member;

use App\Models\Member;
use App\Models\MemberEquipment;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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

    public function updateStatus(Member $member, string $status): Member
    {
        $member->update(['status' => $status]);

        return $member->fresh();
    }

    public function delete(Member $member): void
    {
        DB::transaction(function () use ($member) {
            MemberEquipment::query()->where('member_id', $member->id)->delete();
            ProductReview::query()->where('member_id', $member->id)->update(['member_id' => null]);
            User::query()->where('member_id', $member->id)->where('role', '!=', 'admin')->delete();
            $member->delete();
        });
    }

    public function attachEquipment(string $memberId, string $productId): Member
    {
        abort_unless(auth('api')->user()?->member_id === (int) $memberId, 403);
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
        abort_unless(auth('api')->user()?->member_id === (int) $memberId, 403);
        $member = Member::findOrFail($memberId);

        $equipment = array_values(array_filter(
            $member->equipment ?? [],
            fn (string $id) => $id !== $productId
        ));

        $member->update(['equipment' => $equipment]);

        return $member->fresh();
    }
}
