<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupSocialMedia extends Command
{
    protected $signature = 'social:cleanup';

    protected $description = 'Purge old trashed posts and abandoned private uploads';

    public function handle(): int
    {
        $deleted = DB::table('posts')->where('deleted_at', '<', now()->subDays(30))->pluck('id');
        $media = DB::table('media_assets')->where(function ($q) use ($deleted) {
            $q->whereIn('post_id', $deleted)->orWhere(function ($q) {
                $q->where('created_at', '<', now()->subDay())->whereNull('post_id')->whereNull('story_id')->whereNull('message_id')->whereNull('equipment_id')
                    ->whereNotIn('purpose', ['avatar', 'cover']);
            });
        })->limit(500)->get();
        foreach ($media as $m) {
            Storage::disk($m->disk)->delete($m->path);
            if ($m->poster_path) {
                Storage::disk($m->disk)->delete($m->poster_path);
            }
            DB::table('media_assets')->where('id', $m->id)->delete();
        }
        // Only delete posts after their media have been removed, even across batches.
        DB::table('posts')->whereIn('id', $deleted)->whereNotIn('id', DB::table('media_assets')->whereNotNull('post_id')->select('post_id'))->delete();
        DB::table('social_outbox')->where('published_at', '<', now()->subDays(7))->delete();

        return self::SUCCESS;
    }
}
