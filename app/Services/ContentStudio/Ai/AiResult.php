<?php

namespace App\Services\ContentStudio\Ai;

final class AiResult
{
    /** @param array<string, mixed> $value */
    public function __construct(
        public readonly array $value,
        public readonly string $model,
        public readonly ?string $responseId = null,
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        /** null when pricing is not configured: the cost is unknown, not zero. */
        public readonly ?float $costUsd = null,
        public readonly bool $mock = false,
    ) {}
}
