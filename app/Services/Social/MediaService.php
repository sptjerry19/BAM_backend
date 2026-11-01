<?php

namespace App\Services\Social;

use App\Jobs\Social\ProcessVideo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class MediaService
{
    public function __construct(private readonly VisibilityService $visibility) {}

    public function upload(UploadedFile $file, string $purpose): array
    {
        $user = $this->visibility->viewer()->id;
        $video = str_starts_with((string) $file->getMimeType(), 'video/');
        abort_if(! $video && $file->getSize() > 5 * 1024 * 1024, 422);
        abort_if(DB::table('media_assets')->where('user_id', $user)->where('created_at', '>', now()->subDay())->sum('size') + $file->getSize() > 200 * 1024 * 1024, 429);
        $disk = 'social_private';
        $path = $file->store('social/'.$user, $disk);
        abort_unless($path, 500);
        $id = (string) Str::uuid();
        try {
            DB::transaction(function () use ($id, $user, $purpose, $disk, $path, $file, $video) {
                if (in_array($purpose, ['avatar', 'cover'])) {
                    // Retire old assets; cleanup runs later, after any existing short-lived URLs expire.
                    DB::table('media_assets')->where('user_id', $user)->where('purpose', $purpose)->update(['purpose' => 'retired']);
                }
                DB::table('media_assets')->insert(['id' => $id, 'user_id' => $user, 'purpose' => $purpose,
                    'disk' => $disk, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
                    'status' => $video ? 'processing' : 'ready', 'created_at' => now(), 'updated_at' => now()]);
                if ($purpose === 'avatar') {
                    $this->visibility->viewer()->member?->update(['avatar' => URL::route('social.media', ['media' => $id])]);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
        if ($video) {
            ProcessVideo::dispatch($id);
        }

        return $this->resource(DB::table('media_assets')->find($id), $user);
    }

    public function attach(array $ids, int $user, string $purpose, string $column, int $id): void
    {
        if (! $ids) {
            return;
        }
        $ids = array_unique($ids);
        $assets = DB::table('media_assets')->whereIn('id', $ids)->lockForUpdate()->get();
        abort_unless($assets->count() === count($ids), 422);
        foreach ($assets as $asset) {
            abort_unless($asset->user_id === $user && $asset->purpose === $purpose
                && ! $asset->post_id && ! $asset->message_id && ! $asset->story_id, 403);
        }
        DB::table('media_assets')->whereIn('id', $ids)->update([$column => $id]);
    }

    public function resource(object $media, ?int $viewer): array
    {
        return ['id' => $media->id, 'mime' => $media->mime, 'size' => $media->size, 'status' => $media->status, 'can_manage' => $viewer === $media->user_id,
            'duration' => $media->duration, 'failure_reason' => $media->failure_reason,
            'poster' => $media->poster_path ? $this->signedUrl($media->id, $viewer, true) : null,
            'url' => $media->status !== 'ready' ? null : (in_array($media->purpose, ['avatar', 'cover']) ? URL::route('social.media', ['media' => $media->id])
                : $this->signedUrl($media->id, $viewer))];
    }

    private function signedUrl(string $media, ?int $viewer, bool $poster = false): string
    {
        // Sign the path so TLS termination and proxy host rewrites do not invalidate it.
        $path = URL::temporarySignedRoute('social.media', now()->addMinute(), array_merge(['media' => $media, 'viewer' => $viewer ?? 0], $poster ? ['poster' => 1] : []), false);

        return URL::to($path);
    }

    public function access(string $id): array
    {
        $m = DB::table('media_assets')->find($id);
        abort_unless($m, 404);
        $viewer = $this->visibility->viewer()?->id;
        if (! in_array($m->purpose, ['avatar', 'cover'])) {
            $this->authorize($m, $viewer);
        }

        return $this->resource($m, $viewer);
    }

    public function read(string $id, int $viewer, bool $signed, bool $poster = false)
    {
        $media = DB::table('media_assets')->find($id);
        abort_unless($media && $media->status === 'ready', 404);
        if (! in_array($media->purpose, ['avatar', 'cover'])) {
            abort_unless($signed, 403);
            $this->authorize($media, $viewer ?: null);
        }
        abort_if($poster && ! $media->poster_path, 404);

        return response()->file(Storage::disk($media->disk)->path($poster ? $media->poster_path : $media->path), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $poster ? 'image/jpeg' : $media->mime,
            'Content-Disposition' => 'inline',
        ]);
    }

    public function retry(string $id): void
    {
        DB::transaction(function () use ($id) {
            $m = DB::table('media_assets')->where('id', $id)->lockForUpdate()->first();
            abort_unless($m && $m->user_id === $this->visibility->viewer()->id && $m->status === 'failed', 403);
            DB::table('media_assets')->where('id', $id)->update(['status' => 'processing', 'failure_reason' => null, 'updated_at' => now()]);
        });
        ProcessVideo::dispatch($id);
    }

    public function authorize(object $m, ?int $viewer): void
    {
        if ($m->post_id) {
            app(PostService::class)->find($m->post_id, $viewer);
        } elseif ($m->message_id) {
            $message = DB::table('messages')->find($m->message_id);
            abort_unless($message && $viewer, 404);
            app(ChatService::class)->participant($message->conversation_id, $viewer);
        } elseif ($m->story_id) {
            app(StoryService::class)->find($m->story_id, $viewer);
        } elseif ($m->equipment_id) {
            abort_unless($this->visibility->field($m->user_id, 'equipment', $viewer), 404);
        } else {
            abort_unless($m->user_id === $viewer && $m->purpose !== 'retired', 404);
        }
        if ($m->album_id) {
            $album = DB::table('albums')->find($m->album_id);
            abort_unless($album && $this->visibility->allows($album->user_id, $album->audience, $viewer), 404);
        }
    }

    public function removeProfileImage(string $purpose): void
    {
        abort_unless(in_array($purpose, ['avatar', 'cover']), 404);
        DB::transaction(function () use ($purpose) {
            DB::table('media_assets')->where('user_id', $this->visibility->viewer()->id)->where('purpose', $purpose)->update(['purpose' => 'retired']);
            if ($purpose === 'avatar') {
                $this->visibility->viewer()->member?->update(['avatar' => null]);
            }
        });
    }

    public function equipmentResource(int $equipmentId, string $legacyImage): string
    {
        $m = DB::table('media_assets')->where('equipment_id', $equipmentId)->first();

        return $m ? $this->resource($m, $this->visibility->viewer()?->id)['url'] : $legacyImage;
    }
}
