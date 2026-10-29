<?php

namespace App\Events\CapitalRivals;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class RoomUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly int $version,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('cr.'.$this->code);
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'code' => $this->code,
            'version' => $this->version,
        ];
    }
}
