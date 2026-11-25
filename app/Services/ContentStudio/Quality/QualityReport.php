<?php

namespace App\Services\ContentStudio\Quality;

final class QualityReport
{
    public const READY = 'ready';
    public const NEEDS_REVIEW = 'needs_review';
    public const BLOCKED = 'blocked';

    /** @var list<array{code: string, passed: bool, severity: string, message: string}> */
    private array $checks = [];

    /** @param 'block'|'review' $severity what a failure of this check means */
    public function add(string $code, bool $passed, string $severity, string $message): void
    {
        $this->checks[] = ['code' => $code, 'passed' => $passed, 'severity' => $severity, 'message' => $message];
    }

    /** blocked beats needs_review beats ready. */
    public function verdict(): string
    {
        $failed = $this->failed();
        if (array_filter($failed, fn ($c) => $c['severity'] === 'block') !== []) {
            return self::BLOCKED;
        }

        return $failed === [] ? self::READY : self::NEEDS_REVIEW;
    }

    /** @return list<array{code: string, passed: bool, severity: string, message: string}> */
    public function failed(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => ! $c['passed']));
    }

    /** One line for the dashboard / blocked_reason. */
    public function summary(): string
    {
        return implode('; ', array_map(fn ($c) => $c['code'].': '.$c['message'], $this->failed())) ?: 'all checks passed';
    }

    /** @return array<string, mixed> qa.json */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict(),
            'checked_at' => now()->toIso8601String(),
            'failed' => array_map(fn ($c) => $c['code'], $this->failed()),
            'checks' => $this->checks,
        ];
    }
}
