<?php

namespace App\Services\ContentStudio\State;

use App\Enums\ContentStudio\ContentStatus;
use RuntimeException;

final class InvalidContentTransition extends RuntimeException
{
    public static function between(ContentStatus $from, ContentStatus $to): self
    {
        return new self("Content transition {$from->value} -> {$to->value} is not allowed.");
    }

    public static function reasonRequired(ContentStatus $to): self
    {
        return new self("A reason is required to move a content item to {$to->value}.");
    }
}
