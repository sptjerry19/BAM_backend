<?php

use App\Jobs\Social\ProcessVideo;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

// Run in the built image: all DB state and generated video stay in this container.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'video_smoke', 'database.connections.video_smoke' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true], 'filesystems.disks.social_private.root' => '/tmp/social-video-smoke', 'logging.default' => 'stderr']);
foreach (['2014_10_12_000000_create_users_table', '2024_01_01_000003_create_members_table', '2026_08_03_000004_add_role_and_member_id_to_users_table', '2024_01_01_000002_create_products_table', '2024_01_01_000007_create_member_equipments_table', '2026_10_31_100000_create_social_profile_tables'] as $migration) {
    (require database_path('migrations/'.$migration.'.php'))->up();
}
$user = User::create(['name' => 'Video smoke', 'email' => 'video@example.test', 'password' => 'test-only-password', 'role' => 'user']);
$disk = Storage::disk('social_private');
$disk->makeDirectory('social/'.$user->id);
$id = (string) Str::uuid();
$path = 'social/'.$user->id.'/source.mp4';
(new Process(['ffmpeg', '-nostdin', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=24', '-t', '1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $disk->path($path)]))->setTimeout(30)->mustRun();
DB::table('media_assets')->insert(['id' => $id, 'user_id' => $user->id, 'purpose' => 'post', 'disk' => 'social_private', 'path' => $path, 'mime' => 'video/mp4', 'size' => $disk->size($path), 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
(new ProcessVideo($id))->handle();
$asset = DB::table('media_assets')->find($id);
if ($asset->status !== 'ready' || ! $asset->poster_path || ! $disk->exists($asset->poster_path) || $asset->duration !== 1 || $disk->exists($path)) {
    throw new RuntimeException('Video processing smoke test failed');
}
echo "PASS: actual ProcessVideo job, ffprobe duration, H.264/AAC transcode, poster, DB ready state, source cleanup\n";
