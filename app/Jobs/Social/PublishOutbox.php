<?php

namespace App\Jobs\Social;

use App\Events\Social\UserChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PublishOutbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $id)
    {
        $this->onQueue('broadcasts');
    }

    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $row = DB::table('social_outbox')->where('id', $this->id)->lockForUpdate()->first();
            if (! $row || $row->published_at) {
                return;
            }
            event(new UserChanged($row->user_id, $row->event, array_merge(json_decode($row->payload, true), ['event_id' => $row->id])));
            DB::table('social_outbox')->where('id', $row->id)->update(['published_at' => now()]);
        });
    }
}
