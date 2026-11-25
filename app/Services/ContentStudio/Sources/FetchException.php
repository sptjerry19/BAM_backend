<?php

namespace App\Services\ContentStudio\Sources;

use RuntimeException;

final class FetchException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
