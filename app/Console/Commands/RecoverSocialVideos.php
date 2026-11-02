<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverSocialVideos extends Command
{
    protected $signature = 'social:recover-videos';

    protected $description = 'Make timed-out video jobs available for an explicit retry';

    public function handle(): int
    {
        DB::table('media_assets')->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(20))
            ->update(['status' => 'failed', 'failure_reason' => 'video_processing_timeout', 'updated_at' => now()]);

        return self::SUCCESS;
    }
}
