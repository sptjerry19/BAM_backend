<?php

namespace App\Services\ContentStudio\Review;

use RuntimeException;

/** A reviewer action that is refused. `reasonCode` is stable; the message is for people. */
final class ReviewException extends RuntimeException
{
    public const NOT_IN_REVIEW = 'not_in_review';

    public const MOCK = 'mock_content';

    public const NO_RENDER = 'no_render';

    public const BLOCKING_FINDINGS = 'blocking_findings';

    public const FINDINGS_CHANGED = 'findings_changed';

    public const REASON_REQUIRED = 'reason_required';

    public const NOT_FAILED = 'not_failed';

    public const NOT_CANCELLABLE = 'not_cancellable';

    /** @param list<array{code: string, passed: bool, severity: string, message: string}> $findings */
    public function __construct(public readonly string $reasonCode, string $message, public readonly array $findings = [])
    {
        parent::__construct($message);
    }
}
