<?php

namespace App\Services\Member;

use App\Models\Member;
use App\Models\MemberEquipment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class MemberEquipmentService
{
    /**
     * @return Collection<int, MemberEquipment>
     */
    public function listForMember(string $memberId): Collection
    {
        Member::findOrFail($memberId);

        return MemberEquipment::query()
            ->where('member_id', $memberId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function create(string $memberId, array $data): MemberEquipment
    {
        Member::findOrFail($memberId);

        return MemberEquipment::create([
            ...$data,
            'id' => 'custom_'.Str::uuid(),
            'member_id' => $memberId,
        ]);
    }

    public function delete(string $id): void
    {
        MemberEquipment::findOrFail($id)->delete();
    }
}
