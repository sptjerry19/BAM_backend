<?php

namespace App\Services\Social;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PostService
{
    public function __construct(private readonly VisibilityService $visibility) {}

    public function query(?int $viewer)
    {
        return $this->visibility->scope(DB::table('posts')->whereNull('deleted_at')->whereNull('archived_at'), $viewer);
    }

    public function find(int $id, ?int $viewer = null): object
    {
        $viewer ??= $this->visibility->viewer()?->id;
        $post = DB::table('posts')->find($id);
        abort_unless($post && ! $post->deleted_at && (! $post->archived_at || $post->user_id === $viewer)
            && $this->visibility->allows($post->user_id, $post->audience, $viewer), 404);

        return $post;
    }

    public function list(int $user, array $filters, bool $saved = false): array
    {
        User::findOrFail($user);
        $viewer = $this->visibility->viewer()?->id;
        $query = $this->query($viewer);
        if ($saved) {
            $query->whereIn('posts.id', DB::table('saved_posts')->where('user_id', $viewer)->select('post_id'));
        } else {
            $query->where('posts.user_id', $user)->where('posts.hidden_from_profile', false);
        }
        if (! empty($filters['search'])) {
            $query->where('body', 'like', '%'.$filters['search'].'%');
        }
        // Pin order is part of the cursor; changing a pin restarts the timeline on the client.
        if (! $saved) {
            $query->orderByDesc('posts.pinned');
        }
        $page = $query->orderByDesc('posts.id')->cursorPaginate($filters['per_page'] ?? 10, ['posts.*'], 'cursor', $filters['cursor'] ?? null);

        return ['data' => $this->resources($page->items(), $viewer), 'next_cursor' => $page->nextCursor()?->encode()];
    }

    public function resources(array $posts, ?int $viewer): array
    {
        $ids = array_column($posts, 'id');
        $authors = User::with('member')->whereIn('id', array_column($posts, 'user_id'))->get()->keyBy('id');
        $courtIds = array_filter(array_column($posts, 'court_id'));
        $courts = $courtIds ? DB::table('courts')->whereIn('id', $courtIds)->get(['id', 'name'])->keyBy('id') : collect();
        $media = DB::table('media_assets')->whereIn('post_id', $ids)->get()->groupBy('post_id');
        $reactions = $this->visibility->scopePeople(DB::table('post_reactions')->whereIn('post_id', $ids), $viewer)
            ->select('post_id', 'reaction')->selectRaw('COUNT(*) as aggregate')->groupBy('post_id', 'reaction')->get()->groupBy('post_id');
        $comments = $this->visibility->scopePeople(DB::table('comments')->whereIn('post_id', $ids), $viewer)
            ->select('post_id')->selectRaw('COUNT(*) as aggregate')->groupBy('post_id')->pluck('aggregate', 'post_id');
        $mine = $viewer ? DB::table('post_reactions')->where('user_id', $viewer)->whereIn('post_id', $ids)->pluck('reaction', 'post_id') : collect();
        $albumIds = $media->collapse()->pluck('album_id')->filter()->unique()->all();
        $allowedAlbums = $this->visibility->scope(DB::table('albums')->whereIn('id', $albumIds), $viewer, 'albums')->pluck('id')->flip();
        $saved = $viewer ? DB::table('saved_posts')->where('user_id', $viewer)->whereIn('post_id', $ids)->pluck('post_id')->all() : [];

        return array_map(function ($p) use ($viewer, $authors, $media, $reactions, $comments, $saved, $courts, $mine, $allowedAlbums) {
            $author = $authors[$p->user_id];
            $visibleReactions = $reactions[$p->id] ?? collect();
            $shared = null;
            if ($p->shared_post_id) {
                try {
                    $source = $this->find($p->shared_post_id, $viewer);
                    // A share never creates a second copy of original media/content.
                    $shared = ['id' => $source->id, 'body' => $source->body, 'available' => true];
                } catch (HttpException $e) {
                    $shared = ['available' => false];
                }
            }

            return [
                'id' => $p->id, 'body' => $p->body, 'audience' => $p->audience, 'pinned' => (bool) $p->pinned,
                'court' => $p->court_id ? ($courts[$p->court_id] ?? null) : null,
                'created_at' => $p->created_at, 'updated_at' => $p->updated_at, 'can_manage' => $viewer === $p->user_id,
                'author' => ['id' => $author->id, 'name' => $author->name, 'avatar' => $author->member?->avatar],
                'media' => ($media[$p->id] ?? collect())->filter(fn ($m) => ! $m->album_id || isset($allowedAlbums[$m->album_id]))
                    ->map(fn ($m) => app(MediaService::class)->resource($m, $viewer))->values(),
                'reaction_count' => (int) $visibleReactions->sum('aggregate'), 'reactions' => $visibleReactions->pluck('aggregate', 'reaction'),
                'my_reaction' => $mine[$p->id] ?? null,
                'comment_count' => (int) ($comments[$p->id] ?? 0), 'saved' => in_array($p->id, $saved), 'shared' => $shared,
            ];
        }, $posts);
    }

    public function create(array $data): array
    {
        $user = $this->visibility->viewer()->id;
        if (empty(trim($data['body'] ?? '')) && empty($data['media_ids']) && empty($data['shared_post_id']) && empty($data['court_id'])) {
            throw ValidationException::withMessages(['body' => [__('messages.social_content_required')]]);
        }
        if (! empty($data['shared_post_id'])) {
            $source = $this->find($data['shared_post_id']);
            abort_unless($source->audience === 'public' && ! $source->shared_post_id, 403);
        }
        $id = DB::transaction(function () use ($data, $user) {
            $id = DB::table('posts')->insertGetId(['user_id' => $user, 'body' => $data['body'] ?? null,
                'court_id' => $data['court_id'] ?? null,
                'audience' => $data['audience'] ?? 'public', 'shared_post_id' => $data['shared_post_id'] ?? null,
                'created_at' => now(), 'updated_at' => now()]);
            app(MediaService::class)->attach($data['media_ids'] ?? [], $user, 'post', 'post_id', $id);

            return $id;
        });

        return $this->resources([$this->find($id)], $user)[0];
    }

    public function update(int $id, array $data): array
    {
        $post = $this->owned($id);
        abort_if($post->deleted_at, 404);
        abort_if(array_key_exists('shared_post_id', $data), 422);
        $fields = array_intersect_key($data, array_flip(['body', 'audience', 'court_id']));
        if (array_key_exists('archived', $data)) {
            $fields['archived_at'] = $data['archived'] ? now() : null;
        }
        if (array_key_exists('hidden', $data)) {
            $fields['hidden_from_profile'] = $data['hidden'];
        }
        $existing = DB::table('media_assets')->where('post_id', $id)->pluck('id')->all();
        $keep = array_unique($data['keep_media_ids'] ?? $existing);
        abort_unless(count(array_intersect($keep, $existing)) === count($keep) && count($keep) + count($data['media_ids'] ?? []) <= 10, 422);
        $body = array_key_exists('body', $fields) ? $fields['body'] : $post->body;
        $court = array_key_exists('court_id', $fields) ? $fields['court_id'] : $post->court_id;
        if (! trim($body ?? '') && ! $post->shared_post_id && ! $keep && empty($data['media_ids']) && ! $court) {
            throw ValidationException::withMessages(['body' => [__('messages.social_content_required')]]);
        }
        DB::transaction(function () use ($id, $fields, $keep, $data, $post) {
            DB::table('posts')->where('id', $id)->update(array_merge($fields, ['updated_at' => now()]));
            DB::table('media_assets')->where('post_id', $id)->whereNotIn('id', $keep)->update(['post_id' => null, 'album_id' => null, 'purpose' => 'retired']);
            app(MediaService::class)->attach($data['media_ids'] ?? [], $post->user_id, 'post', 'post_id', $id);
        });

        return $this->resources([$this->find($id)], $post->user_id)[0];
    }

    public function owned(int $id): object
    {
        $post = DB::table('posts')->find($id);
        abort_unless($post && $post->user_id === $this->visibility->viewer()->id, 403);

        return $post;
    }

    public function delete(int $id): void
    {
        $this->owned($id);
        DB::table('posts')->where('id', $id)->update(['deleted_at' => now(), 'pinned' => false]);
    }

    public function restore(int $id): void
    {
        $post = $this->owned($id);
        abort_if($post->deleted_at && $post->deleted_at < now()->subDays(30)->toDateTimeString(), 410);
        DB::table('posts')->where('id', $id)->update(['deleted_at' => null, 'archived_at' => null]);
    }

    public function pin(int $id, bool $pin): void
    {
        $post = $this->owned($id);
        DB::transaction(function () use ($post, $id, $pin) {
            User::whereKey($post->user_id)->lockForUpdate()->firstOrFail();
            DB::table('posts')->where('user_id', $post->user_id)->update(['pinned' => false]);
            DB::table('posts')->where('id', $id)->whereNull('deleted_at')->whereNull('archived_at')->update(['pinned' => $pin]);
        });
    }

    public function reaction(int $id, ?string $reaction): void
    {
        $post = $this->find($id);
        $key = ['post_id' => $id, 'user_id' => $this->visibility->viewer()->id];
        if ($reaction === null) {
            DB::table('post_reactions')->where($key)->delete();
        } else {
            DB::table('post_reactions')->updateOrInsert($key, ['reaction' => $reaction]);
            app(RelationshipService::class)->notify($post->user_id, 'reaction', $id);
        }
    }

    public function save(int $id, bool $save): void
    {
        $this->find($id);
        $key = ['post_id' => $id, 'user_id' => $this->visibility->viewer()->id];
        if ($save) {
            DB::table('saved_posts')->insertOrIgnore($key);
        } else {
            DB::table('saved_posts')->where($key)->delete();
        }
    }

    public function comments(int $id, array $filters): array
    {
        $this->find($id);
        $query = DB::table('comments')->where('post_id', $id);
        $viewer = $this->visibility->viewer()?->id;
        if ($viewer) {
            $query->whereNotIn('user_id', DB::table('user_blocks')->where('user_id', $viewer)->select('target_id'))
                ->whereNotIn('user_id', DB::table('user_blocks')->where('target_id', $viewer)->select('user_id'));
        }
        if (! empty($filters['after'])) {
            $query->where('id', '>', $filters['after']);
        }
        $items = $query->orderBy('id')->limit(31)->get();
        $authors = User::with('member')->whereIn('id', $items->pluck('user_id'))->get()->keyBy('id');

        return ['data' => $items->take(30)->map(fn ($c) => array_merge((array) $c, [
            'author' => ['id' => $c->user_id, 'name' => $authors[$c->user_id]->name, 'avatar' => $authors[$c->user_id]->member?->avatar],
            'can_manage' => $c->user_id === $viewer,
        ]))->values(), 'has_more' => $items->count() > 30];
    }

    public function comment(int $post, array $data): array
    {
        $source = $this->find($post);
        if (! empty($data['parent_id'])) {
            $parent = DB::table('comments')->find($data['parent_id']);
            abort_unless($parent && $parent->post_id === $post && ! $parent->parent_id
                && ! $this->visibility->blocked($parent->user_id, $this->visibility->viewer()->id), 422);
        }
        $id = DB::table('comments')->insertGetId(['post_id' => $post, 'user_id' => $this->visibility->viewer()->id,
            'body' => $data['body'], 'parent_id' => $data['parent_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        app(RelationshipService::class)->notify($source->user_id, 'comment', $post);

        return ['id' => $id];
    }

    public function modifyComment(int $id, ?array $data): void
    {
        $comment = DB::table('comments')->find($id);
        abort_unless($comment && $comment->user_id === $this->visibility->viewer()->id, 403);
        $this->find($comment->post_id);
        if ($data) {
            DB::table('comments')->where('id', $id)->update(['body' => $data['body'], 'updated_at' => now()]);
        } else {
            DB::table('comments')->where('id', $id)->delete();
        }
    }
}
