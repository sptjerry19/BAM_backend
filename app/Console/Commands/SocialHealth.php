<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

class SocialHealth extends Command
{
    protected $signature = 'social:health';

    protected $description = 'Check schema and Redis before starting social background services';

    public function handle(): int
    {
        try {
            if (! Schema::hasTable('photo_tags') || ! Schema::hasColumn('posts', 'court_id') || ! Schema::hasColumn('posts', 'hidden_from_profile')) {
                return self::FAILURE;
            }
            if (! config('broadcasting.connections.reverb.key') || ! config('broadcasting.connections.reverb.secret')) {
                return self::FAILURE;
            }
            Redis::connection()->ping();

            return self::SUCCESS;
        } catch (\Throwable) {
            return self::FAILURE;
        }
    }
}
