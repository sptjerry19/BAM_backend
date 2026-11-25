<?php

namespace App\Services\ContentStudio\Ai;

use RuntimeException;

/**
 * One exception type with a `kind`, so callers decide by kind instead of by class.
 *
 *  disabled        feature flag off or key missing             -> never retry
 *  budget          daily/per-run budget used up                -> never retry
 *  permanent       400/401/403/404/quota, bad request          -> never retry
 *  refusal         the model refused                           -> never retry
 *  transient       429, 5xx, timeout, network                  -> retry with back-off
 *  invalid_output  incomplete / empty / not JSON               -> retry (bounded)
 */
final class AiException extends RuntimeException
{
    private function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function disabled(string $why): self
    {
        return new self('disabled', $why);
    }

    public static function budget(string $why): self
    {
        return new self('budget', $why);
    }

    public static function permanent(string $why, ?int $status = null): self
    {
        return new self('permanent', $why, null, $status);
    }

    public static function refusal(string $why): self
    {
        return new self('refusal', $why);
    }

    public static function transient(string $why, ?int $status = null, ?int $retryAfter = null): self
    {
        return new self('transient', $why, $retryAfter, $status);
    }

    public static function invalidOutput(string $why): self
    {
        return new self('invalid_output', $why);
    }

    public function retryable(): bool
    {
        return in_array($this->kind, ['transient', 'invalid_output'], true);
    }
}
