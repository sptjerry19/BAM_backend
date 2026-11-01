<?php

namespace App\Console\Commands;

use App\Jobs\Social\PublishOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PublishSocialOutbox extends Command
{
    protected $signature = 'social:outbox';

    protected $description = 'Dispatch durable social events to the broadcasts queue';

    public function handle(): int
    {
        DB::table('social_outbox')->whereNull('published_at')->orderBy('id')->limit(100)->pluck('id')->each(function ($id) {
            // Pending rows remain the source of truth; throttle dispatches while workers retry.
            if (Cache::add('social:outbox:'.$id, true, 90)) {
                try {
                    PublishOutbox::dispatch($id);
                } catch (\Throwable $e) {
                    Cache::forget('social:outbox:'.$id);
                    throw $e;
                }
            }
        });

        return self::SUCCESS;
    }
}
