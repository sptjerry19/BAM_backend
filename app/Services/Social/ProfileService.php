<?php

namespace App\Services\Social;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProfileService
{
    public function __construct(private readonly VisibilityService $visibility) {}

    public function show(int $id): array
    {
        $user = User::with('member')->findOrFail($id);
        $viewer = $this->visibility->viewer()?->id;
        abort_if($user->member && $user->member->status !== 'active' && $viewer !== $id && ! $this->visibility->viewer()?->isAdmin(), 404);
        // The blocking user can still see an unblock action, but never private fields.
        $blockedByMe = $viewer && DB::table('user_blocks')->where('user_id', $viewer)->where('target_id', $id)->exists();
        abort_if($this->visibility->blocked($id, $viewer) && ! $blockedByMe, 404);
        $profile = (array) DB::table('user_profiles')->where('user_id', $id)->first();
        unset($profile['id'], $profile['user_id'], $profile['created_at'], $profile['updated_at']);
        $profile = $this->visibility->field($id, 'about', $viewer) ? $profile : [];
        $relation = $viewer ? DB::table('friendships')->where('user_low', min($id, $viewer))->where('user_high', max($id, $viewer))->first() : null;
        $data = array_merge($profile, [
            'id' => $id, 'member_id' => $user->member_id, 'name' => $user->name,
            'avatar' => $user->member?->avatar, 'level' => $user->member?->level,
            'points' => $user->member?->points ?? 0, 'matches' => $user->member?->matches ?? 0,
            'wins' => $user->member?->wins ?? 0, 'join_date' => $user->member?->join_date?->toDateString(),
            'is_owner' => $viewer === $id, 'blocked_by_me' => (bool) $blockedByMe,
            'friendship' => $relation ? ['id' => $relation->id, 'status' => $relation->status, 'requested_by' => $relation->requested_by] : null,
            'following' => $viewer && DB::table('user_follows')->where('user_id', $viewer)->where('target_id', $id)->exists(),
            'can_view_equipment' => $this->visibility->field($id, 'equipment', $viewer),
            'can_view_friends' => $this->visibility->field($id, 'friends', $viewer),
        ]);
        $cover = DB::table('media_assets')->where('user_id', $id)->where('purpose', 'cover')->first();
        $data['cover'] = $cover ? app(MediaService::class)->resource($cover, $viewer)['url'] : null;
        foreach (['email' => $user->email, 'phone' => $user->member?->phone] as $field => $value) {
            if ($this->visibility->field($id, $field, $viewer)) {
                $data[$field] = $value;
            }
        }
        if ($viewer === $id) {
            $data['total_cost'] = $user->member?->total_cost ?? 0;
            $data['privacy'] = DB::table('profile_privacy_settings')->where('user_id', $id)->pluck('audience', 'field');
        }
        if ($data['can_view_friends']) {
            $data['friend_count'] = DB::table('friendships')->where('status', 'accepted')
                ->where(fn ($q) => $q->where('user_low', $id)->orWhere('user_high', $id))->count();
            $data['follower_count'] = DB::table('user_follows')->where('target_id', $id)->count();
            $data['following_count'] = DB::table('user_follows')->where('user_id', $id)->count();
        }

        return $data;
    }

    public function update(array $data): array
    {
        $user = $this->visibility->viewer();
        DB::transaction(function () use ($user, $data) {
            if (isset($data['name'])) {
                $user->update(['name' => $data['name']]);
                $user->member?->update(['name' => $data['name']]);
            }
            $fields = array_diff_key($data, ['name' => true]);
            DB::table('user_profiles')->updateOrInsert(['user_id' => $user->id], array_merge($fields, ['updated_at' => now()]));
        });

        return $this->show($user->id);
    }

    public function privacy(array $data): array
    {
        $id = $this->visibility->viewer()->id;
        foreach ($data as $field => $audience) {
            DB::table('profile_privacy_settings')->updateOrInsert(['user_id' => $id, 'field' => $field], ['audience' => $audience]);
        }

        return $this->show($id);
    }

    public function equipment(int $id, array $filters): array
    {
        $user = User::findOrFail($id);
        abort_unless($this->visibility->field($id, 'equipment', $this->visibility->viewer()?->id), 403);

        return $this->equipmentForMember($user->member_id, $filters, $this->visibility->viewer()?->id === $id);
    }

    public function equipmentForMember(?int $memberId, array $filters = [], bool $owner = false): array
    {
        if (! $memberId) {
            return ['data' => [], 'can_manage' => false, 'has_more' => false];
        }
        $member = Member::findOrFail($memberId);
        abort_unless($member->status === 'active', 404);
        $products = DB::table('products')->whereIn('id', $member->equipment ?? [])
            ->selectRaw("'store' as source, id, id as product_id, name, brand, category, image, NULL as price, NULL as notes");
        $custom = DB::table('member_equipments')->where('member_id', $memberId)
            ->selectRaw("'custom' as source, id, NULL as product_id, name, brand, category, image, ".($owner ? 'price, notes' : 'NULL as price, NULL as notes'));
        $query = DB::query()->fromSub($products->unionAll($custom), 'equipment');
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 12;
        $result = $query->orderBy('source')->orderBy('id')->simplePaginate($perPage, ['*'], 'page', $page);
        $privateImages = DB::table('media_assets')->whereIn('equipment_id', collect($result->items())->where('source', 'custom')->pluck('id'))->get()->keyBy('equipment_id');
        $items = collect($result->items())->map(function ($item) use ($privateImages, $owner) {
            $data = (array) $item;
            if ($item->source === 'custom' && isset($privateImages[$item->id])) {
                $data['image'] = app(MediaService::class)->resource($privateImages[$item->id], $this->visibility->viewer()?->id)['url'];
            }
            if (! $owner) {
                unset($data['price'], $data['notes']);
            }

            return $data;
        });

        return ['data' => $items, 'can_manage' => $owner, 'has_more' => $result->hasMorePages()];
    }

    public function member(int $memberId): array
    {
        $user = User::where('member_id', $memberId)->first();
        if ($user) {
            return $this->show($user->id);
        }
        $member = Member::findOrFail($memberId);

        abort_unless($member->status === 'active', 404);

        return ['id' => null, 'member_id' => $member->id, 'name' => $member->name,
            'avatar' => $member->avatar, 'level' => $member->level, 'points' => $member->points,
            'matches' => $member->matches, 'wins' => $member->wins, 'is_owner' => false,
            'can_view_equipment' => true, 'can_view_friends' => false];
    }

    public function memberEquipment(int $memberId, array $filters): array
    {
        $user = User::where('member_id', $memberId)->first();

        return $user ? $this->equipment($user->id, $filters) : $this->equipmentForMember($memberId, $filters);
    }
}
