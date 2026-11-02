<?php

namespace App\Services\Social;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class LibraryService
{
    public function __construct(private readonly VisibilityService $visibility) {}

    public function photos(int $user, array $filters): array
    {
        $viewer = $this->visibility->viewer()?->id;
        $query = DB::table('media_assets')->where('media_assets.user_id', $user)->where('status', 'ready')
            ->whereIn('post_id', app(PostService::class)->query($viewer)->select('posts.id'));
        if (($filters['type'] ?? 'image') === 'video') {
            $query->where('mime', 'like', 'video/%');
        } else {
            $query->where('mime', 'like', 'image/%');
        }
        $query->where(function ($q) use ($viewer) {
            $q->whereNull('album_id')->orWhereIn('album_id', $this->visibility->scope(DB::table('albums'), $viewer, 'albums')->select('albums.id'));
        });
        $page = $query->orderByDesc('media_assets.created_at')->orderByDesc('media_assets.id')->simplePaginate(18, ['media_assets.*'], 'page', $filters['page'] ?? 1);

        return ['data' => collect($page->items())->map(fn ($m) => app(MediaService::class)->resource($m, $viewer)), 'has_more' => $page->hasMorePages()];
    }

    public function albums(int $user): array
    {
        return $this->visibility->scope(DB::table('albums')->where('user_id', $user), $this->visibility->viewer()?->id, 'albums')->orderByDesc('id')->limit(50)->get()->all();
    }

    public function album(array $data, ?int $id = null): array
    {
        $user = $this->visibility->viewer()->id;

        return DB::transaction(function () use ($data, $id, $user) {
            if ($id) {
                abort_unless(DB::table('albums')->where('id', $id)->where('user_id', $user)->exists(), 403);
            }
            $ids = array_unique($data['media_ids'] ?? []);
            $media = DB::table('media_assets')->whereIn('id', $ids)->lockForUpdate()->get();
            abort_unless($media->count() === count($ids), 422);
            foreach ($media as $m) {
                abort_unless($m->user_id === $user && $m->post_id && (! $m->album_id || $m->album_id === $id), 403);
                app(PostService::class)->find($m->post_id);
            }
            $fields = ['name' => $data['name'], 'description' => $data['description'] ?? null, 'audience' => $data['audience'], 'updated_at' => now()];
            if ($id) {
                DB::table('albums')->where('id', $id)->update($fields);
                DB::table('media_assets')->where('album_id', $id)->update(['album_id' => null]);
            } else {
                $id = DB::table('albums')->insertGetId($fields + ['user_id' => $user, 'created_at' => now()]);
            }
            DB::table('media_assets')->whereIn('id', $ids)->update(['album_id' => $id]);

            return ['id' => $id];
        });
    }

    public function albumContents(int $id): array
    {
        $album = DB::table('albums')->find($id);
        $viewer = $this->visibility->viewer()?->id;
        abort_unless($album && $this->visibility->allows($album->user_id, $album->audience, $viewer), 404);
        $media = DB::table('media_assets')->where('album_id', $id)
            ->whereIn('post_id', app(PostService::class)->query($viewer)->select('posts.id'))->get();

        return ['album' => $album, 'data' => $media->map(fn ($m) => app(MediaService::class)->resource($m, $viewer))];
    }

    public function deleteAlbum(int $id): void
    {
        abort_unless(DB::table('albums')->where('id', $id)->where('user_id', $this->visibility->viewer()->id)->exists(), 403);
        DB::table('albums')->where('id', $id)->delete();
    }

    public function tag(int $post, int $target): void
    {
        app(PostService::class)->owned($post);
        User::findOrFail($target);
        abort_if($this->visibility->blocked($target, $this->visibility->viewer()->id), 403);
        DB::table('post_tags')->insertOrIgnore(['post_id' => $post, 'user_id' => $target, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        app(RelationshipService::class)->notify($target, 'tag', $post);
    }

    public function reviewTag(int $id, string $status): void
    {
        abort_unless(DB::table('post_tags')->where('id', $id)->where('user_id', $this->visibility->viewer()->id)->exists(), 403);
        DB::table('post_tags')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
    }

    public function photoTag(string $media, int $target): void
    {
        $m = DB::table('media_assets')->find($media);
        $user = $this->visibility->viewer()->id;
        abort_unless($m && $m->user_id === $user && $m->post_id && ! $this->visibility->blocked($user, $target), 403);
        User::findOrFail($target);
        app(PostService::class)->find($m->post_id);
        DB::table('photo_tags')->insertOrIgnore(['media_id' => $media, 'user_id' => $target, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        app(RelationshipService::class)->notify($target, 'tag', $m->post_id);
    }

    public function reviewPhotoTag(int $id, string $status): void
    {
        abort_unless(DB::table('photo_tags')->where('id', $id)->where('user_id', $this->visibility->viewer()->id)->exists(), 403);
        DB::table('photo_tags')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
    }

    public function tagged(int $user, array $filters): array
    {
        $viewer = $this->visibility->viewer()?->id;
        $posts = app(PostService::class)->query($viewer)->where(function ($q) use ($user) {
            $q->whereIn('id', DB::table('post_tags')->where('user_id', $user)->where('status', 'approved')->select('post_id'))
                ->orWhereIn('id', DB::table('media_assets')->whereIn('id', DB::table('photo_tags')->where('user_id', $user)->where('status', 'approved')->select('media_id'))->whereNotNull('post_id')->select('post_id'));
        })
            ->orderByDesc('id')->simplePaginate(10, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => app(PostService::class)->resources($posts->items(), $viewer), 'has_more' => $posts->hasMorePages()];
    }

    public function activity(array $filters): array
    {
        $user = $this->visibility->viewer()->id;

        return [
            'posts' => DB::table('posts')->where('user_id', $user)->orderByDesc('id')->paginate(20, ['id', 'body', 'audience', 'archived_at', 'deleted_at', 'hidden_from_profile', 'created_at'], 'page', $filters['page'] ?? 1)->toArray(),
            'tags' => DB::table('post_tags')->where('user_id', $user)->where('status', 'pending')->get(),
            'photo_tags' => DB::table('photo_tags')->join('media_assets', 'media_assets.id', '=', 'photo_tags.media_id')->where('photo_tags.user_id', $user)
                ->where('photo_tags.status', 'pending')->select('photo_tags.id', 'media_assets.post_id')->get(),
        ];
    }
}
