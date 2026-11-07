<?php

namespace App\Enums\ContentStudio;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }
}
