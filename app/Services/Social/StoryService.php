<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StoryService
{
    public function __construct(private readonly VisibilityService $visibility) {}

    public function find(int $id, ?int $viewer): object
    {
        $story = DB::table('stories')->find($id);
        abort_unless($story && $this->visibility->allows($story->user_id, $story->audience, $viewer), 404);
        if ($story->expires_at <= now()->toDateTimeString() && $story->user_id !== $viewer) {
            $highlights = DB::table('story_highlights')->whereIn('id', DB::table('story_highlight_items')->where('story_id', $id)->select('highlight_id'))->get();
            abort_unless($highlights->contains(fn ($h) => $this->visibility->allows($h->user_id, $h->audience, $viewer)), 404);
        }

        return $story;
    }

    public function list(int $user, bool $archive = false): array
    {
        $viewer = $this->visibility->viewer()?->id;
        abort_if($archive && $viewer !== $user, 403);
        $query = $this->visibility->scope(DB::table('stories')->where('user_id', $user), $viewer, 'stories');
        $archive ? $query->where('expires_at', '<=', now()) : $query->where('expires_at', '>', now());

        return $query->orderByDesc('id')->limit(50)->get()->map(fn ($s) => $this->resource($s, $viewer))->all();
    }

    public function resource(object $s, ?int $viewer): array
    {
        return array_merge((array) $s, ['media' => DB::table('media_assets')->where('story_id', $s->id)->get()
            ->map(fn ($m) => app(MediaService::class)->resource($m, $viewer)),
            'can_manage' => $viewer === $s->user_id]);
    }

    public function create(array $data): array
    {
        $user = $this->visibility->viewer()->id;
        $id = DB::transaction(function () use ($user, $data) {
            $id = DB::table('stories')->insertGetId(['user_id' => $user, 'body' => $data['body'] ?? null,
                'audience' => $data['audience'], 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
            app(MediaService::class)->attach($data['media_ids'], $user, 'story', 'story_id', $id);

            return $id;
        });

        return $this->resource($this->find($id, $user), $user);
    }

    public function view(int $id, ?string $reaction = null): void
    {
        $user = $this->visibility->viewer()->id;
        $story = $this->find($id, $user);
        if ($story->user_id === $user) {
            return;
        }
        $fields = ['created_at' => now()];
        if ($reaction) {
            $fields['reaction'] = $reaction;
        }
        DB::table('story_views')->updateOrInsert(['story_id' => $id, 'user_id' => $user], $fields);
    }

    public function views(int $id): array
    {
        $story = $this->find($id, $this->visibility->viewer()->id);
        abort_unless($story->user_id === $this->visibility->viewer()->id, 403);

        return DB::table('story_views')->join('users', 'users.id', '=', 'story_views.user_id')->where('story_id', $id)
            ->select('users.id', 'users.name', 'story_views.reaction', 'story_views.created_at')->orderByDesc('story_views.id')->limit(100)->get()->all();
    }

    public function delete(int $id): void
    {
        $s = $this->find($id, $this->visibility->viewer()->id);
        abort_unless($s->user_id === $this->visibility->viewer()->id, 403);
        foreach (DB::table('media_assets')->where('story_id', $id)->get() as $m) {
            Storage::disk($m->disk)->delete(array_filter([$m->path, $m->poster_path]));
        }
        DB::table('stories')->where('id', $id)->delete();
    }

    public function highlights(int $user): array
    {
        $viewer = $this->visibility->viewer()?->id;

        return $this->visibility->scope(DB::table('story_highlights')->where('user_id', $user), $viewer, 'story_highlights')
            ->orderByDesc('id')->limit(30)->get()->map(function ($h) use ($viewer) {
                $stories = DB::table('stories')->whereIn('id', DB::table('story_highlight_items')->where('highlight_id', $h->id)->select('story_id'))->get()
                    ->filter(fn ($s) => $this->visibility->allows($s->user_id, $s->audience, $viewer));

                return array_merge((array) $h, ['stories' => $stories->map(fn ($s) => $this->resource($s, $viewer))->values()]);
            })->all();
    }

    public function highlight(array $data, ?int $id = null): array
    {
        $user = $this->visibility->viewer()->id;
        if ($id) {
            abort_unless(DB::table('story_highlights')->where('id', $id)->where('user_id', $user)->exists(), 403);
        }
        $id = DB::transaction(function () use ($user, $data, $id) {
            $ids = array_unique($data['story_ids']);
            abort_unless(DB::table('stories')->whereIn('id', $ids)->where('user_id', $user)->count() === count($ids), 403);
            $fields = ['user_id' => $user, 'name' => $data['name'], 'audience' => $data['audience'], 'updated_at' => now()];
            if ($id) {
                DB::table('story_highlights')->where('id', $id)->update($fields);
            } else {
                $id = DB::table('story_highlights')->insertGetId($fields + ['created_at' => now()]);
            }
            DB::table('story_highlight_items')->where('highlight_id', $id)->delete();
            foreach ($ids as $story) {
                DB::table('story_highlight_items')->insert(['highlight_id' => $id, 'story_id' => $story]);
            }

            return $id;
        });

        return ['id' => $id];
    }

    public function deleteHighlight(int $id): void
    {
        abort_unless(DB::table('story_highlights')->where('id', $id)->where('user_id', $this->visibility->viewer()->id)->exists(), 403);
        DB::table('story_highlights')->where('id', $id)->delete();
    }
}
