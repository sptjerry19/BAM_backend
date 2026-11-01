<?php

namespace App\Services\Social;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RelationshipService
{
    public function __construct(private readonly VisibilityService $visibility) {}

    public function act(int $target, string $action): array
    {
        $actor = $this->visibility->viewer()->id;
        User::findOrFail($target);
        abort_if($actor === $target, 422);
        DB::transaction(function () use ($actor, $target, $action) {
            // Lock users in a deterministic order; protects first-request races too.
            User::whereIn('id', [$actor, $target])->orderBy('id')->lockForUpdate()->get();
            $pair = ['user_low' => min($actor, $target), 'user_high' => max($actor, $target)];
            $key = ['user_id' => $actor, 'target_id' => $target];
            if ($action === 'unblock') {
                DB::table('user_blocks')->where($key)->delete();

                return;
            }
            if ($action === 'block') {
                DB::table('user_blocks')->insertOrIgnore($key + ['created_at' => now()]);
                DB::table('friendships')->where($pair)->delete();
                DB::table('user_follows')->where(fn ($q) => $q->where($key))
                    ->orWhere(fn ($q) => $q->where(['user_id' => $target, 'target_id' => $actor]))->delete();
                DB::table('social_notifications')->where(fn ($q) => $q->where('user_id', $actor)->where('actor_id', $target))
                    ->orWhere(fn ($q) => $q->where('user_id', $target)->where('actor_id', $actor))->delete();

                return;
            }
            abort_if($this->visibility->blocked($actor, $target), 403);
            if (in_array($action, ['follow', 'unfollow'])) {
                $action === 'follow' ? DB::table('user_follows')->insertOrIgnore($key + ['created_at' => now()])
                    : DB::table('user_follows')->where($key)->delete();

                return;
            }
            $friendship = DB::table('friendships')->where($pair)->first();
            if ($action === 'request') {
                if (! $friendship) {
                    DB::table('friendships')->insert($pair + ['requested_by' => $actor, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                    $this->notify($target, 'friend_request', $actor);
                }

                // Opposite requests do not silently accept on the user's behalf.
                return;
            }
            if ($action === 'accept') {
                abort_unless($friendship && $friendship->requested_by === $target, 403);
                DB::table('friendships')->where($pair)->update(['status' => 'accepted', 'updated_at' => now()]);
                DB::table('user_follows')->insertOrIgnore([$key + ['created_at' => now()], ['user_id' => $target, 'target_id' => $actor, 'created_at' => now()]]);
                $this->notify($target, 'friend_accepted', $actor);

                return;
            }
            if (! $friendship) {
                return;
            }
            abort_unless(($action === 'cancel' && $friendship->status === 'pending' && $friendship->requested_by === $actor)
                || ($action === 'reject' && $friendship->status === 'pending' && $friendship->requested_by === $target)
                || ($action === 'unfriend' && $friendship->status === 'accepted'), 403);
            DB::table('friendships')->where($pair)->delete();
        });

        return app(ProfileService::class)->show($target);
    }

    public function people(int $owner, string $type, array $filters): array
    {
        $viewer = $this->visibility->viewer()?->id;
        if ($type !== 'search') {
            User::findOrFail($owner);
            abort_unless($this->visibility->field($owner, 'friends', $viewer), 403);
        }
        $query = User::with('member')->where('role', '!=', 'admin');
        if (in_array($type, ['friends', 'mutual'])) {
            $query->whereIn('id', DB::table('friendships')->where('status', 'accepted')
                ->where(fn ($q) => $q->where('user_low', $owner)->orWhere('user_high', $owner))
                ->selectRaw('CASE WHEN user_low = ? THEN user_high ELSE user_low END', [$owner]));
            if ($type === 'mutual') {
                abort_unless($viewer, 401);
                $query->whereIn('id', DB::table('friendships')->where('status', 'accepted')
                    ->where(fn ($q) => $q->where('user_low', $viewer)->orWhere('user_high', $viewer))
                    ->selectRaw('CASE WHEN user_low = ? THEN user_high ELSE user_low END', [$viewer]));
            }
        } elseif (in_array($type, ['followers', 'following'])) {
            $query->whereIn('id', DB::table('user_follows')->where($type === 'followers' ? 'target_id' : 'user_id', $owner)
                ->select($type === 'followers' ? 'user_id' : 'target_id'));
        } elseif ($type === 'requests') {
            abort_unless($owner === $viewer, 403);
            $query->whereIn('id', DB::table('friendships')->where('status', 'pending')->where('requested_by', '!=', $owner)
                ->where(fn ($q) => $q->where('user_low', $owner)->orWhere('user_high', $owner))->select('requested_by'));
        } elseif ($type !== 'search') {
            abort(404);
        }
        if ($viewer) {
            $query->whereNotIn('id', DB::table('user_blocks')->where('user_id', $viewer)->select('target_id'))
                ->whereNotIn('id', DB::table('user_blocks')->where('target_id', $viewer)->select('user_id'));
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhereIn('id', DB::table('user_profiles')->where('username', 'like', '%'.$search.'%')->select('user_id')));
        }
        $page = $query->orderBy('id')->simplePaginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => collect($page->items())->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'avatar' => $u->member?->avatar]), 'has_more' => $page->hasMorePages()];
    }

    public function notify(int $target, string $type, int $subject): void
    {
        $actor = $this->visibility->viewer()?->id;
        if (! $actor || $actor === $target || $this->visibility->blocked($actor, $target)) {
            return;
        }
        DB::transaction(function () use ($target, $actor, $type, $subject) {
            // Repeated reactions/comments update a single notice per actor/subject/type.
            DB::table('social_notifications')->upsert([['user_id' => $target, 'actor_id' => $actor, 'type' => $type, 'subject_id' => $subject,
                'read_at' => null, 'created_at' => now(), 'updated_at' => now()]], ['user_id', 'actor_id', 'type', 'subject_id'], ['read_at', 'updated_at']);
            app(ChatService::class)->outbox($target, 'NotificationChanged', []);
        });
    }

    public function notifications(array $filters): array
    {
        $user = $this->visibility->viewer()->id;
        $query = DB::table('social_notifications')->where('user_id', $user)->orderByDesc('id');
        if (! empty($filters['before'])) {
            $query->where('id', '<', $filters['before']);
        }
        $rows = $query->limit(31)->get();
        $data = [];
        foreach ($rows->take(30) as $row) {
            if ($this->visibility->blocked($row->actor_id, $user)) {
                continue;
            }
            if (in_array($row->type, ['reaction', 'comment', 'tag'])) {
                try {
                    app(PostService::class)->find($row->subject_id);
                } catch (HttpException $e) {
                    continue;
                }
            }
            $actor = User::with('member')->find($row->actor_id);
            $data[] = array_merge((array) $row, ['actor' => ['id' => $actor->id, 'name' => $actor->name, 'avatar' => $actor->member?->avatar]]);
        }

        return ['data' => $data, 'next_before' => $rows->count() > 30 ? $rows[29]->id : null];
    }

    public function readNotification(?int $id): void
    {
        DB::table('social_notifications')->where('user_id', $this->visibility->viewer()->id)
            ->when($id, fn ($q) => $q->where('id', $id))->update(['read_at' => now()]);
        app(ChatService::class)->outbox($this->visibility->viewer()->id, 'NotificationChanged', []);
    }

    public function counts(): array
    {
        $user = $this->visibility->viewer()->id;
        $notices = DB::table('social_notifications')->where('user_id', $user)->whereNull('read_at')
            ->whereNotIn('actor_id', DB::table('user_blocks')->where('user_id', $user)->select('target_id'))
            ->whereNotIn('actor_id', DB::table('user_blocks')->where('target_id', $user)->select('user_id'))
            ->where(function ($q) use ($user) {
                $q->whereNotIn('type', ['reaction', 'comment', 'tag'])
                    ->orWhereIn('subject_id', app(PostService::class)->query($user)->select('posts.id'));
            })->count();
        $messages = DB::table('messages')->join('conversation_participants as p', 'p.conversation_id', '=', 'messages.conversation_id')
            ->where('p.user_id', $user)->where('messages.user_id', '!=', $user)->whereColumn('messages.id', '>', 'p.last_read_id')->count();

        return ['notifications' => $notices, 'messages' => $messages];
    }

    public function report(array $data): void
    {
        $table = ['user' => 'users', 'post' => 'posts', 'comment' => 'comments'][$data['subject_type']];
        abort_unless(DB::table($table)->where('id', $data['subject_id'])->exists(), 404);
        if ($table === 'posts') {
            app(PostService::class)->find($data['subject_id']);
        }
        if ($table === 'comments') {
            app(PostService::class)->find(DB::table('comments')->where('id', $data['subject_id'])->value('post_id'));
        }
        DB::table('reports')->insert($data + ['user_id' => $this->visibility->viewer()->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function reports(array $filters): array
    {
        return DB::table('reports')->orderByDesc('id')->paginate(20, ['*'], 'page', $filters['page'] ?? 1)->toArray();
    }

    public function reviewReport(int $id, string $status): void
    {
        abort_unless(DB::table('reports')->where('id', $id)->exists(), 404);
        DB::table('reports')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
    }
}
