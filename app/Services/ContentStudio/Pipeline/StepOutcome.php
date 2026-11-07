<?php

namespace App\Services\ContentStudio\Pipeline;

final class StepOutcome
{
    public const COMPLETED = 'completed';
    public const BUSY = 'busy';
    public const PAUSED = 'paused';
    public const EXHAUSTED = 'exhausted';

    /** @param array<string, mixed> $output */
    private function __construct(public readonly string $state, public readonly array $output = []) {}

    /** @param array<string, mixed> $output */
    public static function completed(array $output = []): self
    {
        return new self(self::COMPLETED, $output);
    }

    /** Another worker holds a live lease on this step. */
    public static function busy(): self
    {
        return new self(self::BUSY);
    }

    /** Automation is paused: the step was not started. */
    public static function paused(): self
    {
        return new self(self::PAUSED);
    }

    /** All attempts are used up; the run has been marked failed. */
    public static function exhausted(): self
    {
        return new self(self::EXHAUSTED);
    }

    public function isCompleted(): bool
    {
        return $this->state === self::COMPLETED;
    }
}
