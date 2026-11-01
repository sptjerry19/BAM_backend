<?php

namespace App\Jobs\Social;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ProcessVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $id)
    {
        $this->onQueue('media');
    }

    public function handle(): void
    {
        $asset = DB::table('media_assets')->find($this->id);
        if (! $asset || $asset->status === 'ready') {
            return;
        }
        $disk = Storage::disk($asset->disk);
        $source = $disk->path($asset->path);
        $output = 'social/'.$asset->user_id.'/'.$asset->id.'.mp4';
        $poster = 'social/'.$asset->user_id.'/'.$asset->id.'.jpg';
        try {
            $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'json', $source]);
            $probe->setTimeout(20)->mustRun();
            $duration = (float) (json_decode($probe->getOutput(), true)['format']['duration'] ?? 0);
            if ($duration <= 0 || $duration > ($asset->purpose === 'story' ? 60 : 300)) {
                throw new \RuntimeException('video_duration_invalid');
            }
            $encode = new Process(['ffmpeg', '-nostdin', '-y', '-i', $source, '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', "scale='min(1280,iw)':-2", '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '25',
                '-c:a', 'aac', '-movflags', '+faststart', '-threads', '2', $disk->path($output)]);
            $encode->setTimeout(540)->mustRun();
            (new Process(['ffmpeg', '-nostdin', '-y', '-i', $disk->path($output), '-frames:v', '1', '-vf', 'scale=640:-2', $disk->path($poster)]))->setTimeout(30)->mustRun();
            DB::table('media_assets')->where('id', $this->id)->update(['path' => $output, 'mime' => 'video/mp4',
                'poster_path' => $poster, 'duration' => (int) ceil($duration), 'size' => $disk->size($output),
                'status' => 'ready', 'failure_reason' => null, 'updated_at' => now()]);
            if ($asset->path !== $output) {
                $disk->delete($asset->path);
            }
        } catch (\Throwable $e) {
            $disk->delete([$output, $poster]);
            DB::table('media_assets')->where('id', $this->id)->update(['status' => 'failed',
                'failure_reason' => $e->getMessage() === 'video_duration_invalid' ? 'video_duration_invalid' : 'video_processing_failed', 'updated_at' => now()]);
            report($e);
        }
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('social-video:'.$this->id))->releaseAfter(30)->expireAfter(660)];
    }
}
