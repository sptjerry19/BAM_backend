<?php

namespace App\Services\ContentStudio\Render;

use RuntimeException;

/**
 * $retryable is true only for infrastructure trouble (browser crash, timeout) where
 * trying again can help. Bad data (text that does not fit, empty slides, missing font)
 * fails the same way every time, so it must not burn retries.
 */
final class RenderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
