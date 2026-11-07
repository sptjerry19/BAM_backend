<?php

namespace App\Enums\ContentStudio;

/**
 * Life cycle of a content item. The only allowed way to change it is
 * App\Services\ContentStudio\State\ContentStateMachine.
 */
enum ContentStatus: string
{
    case Idea = 'idea';
    case Researching = 'researching';
    case SourceVerified = 'source_verified';
    case Scripted = 'scripted';
    case Producing = 'producing';
    case QualityCheck = 'quality_check';
    case NeedsReview = 'needs_review';
    case Ready = 'ready';
    case Scheduling = 'scheduling';
    case Scheduled = 'scheduled';
    case PublishUnknown = 'publish_unknown';
    case Reconciliation = 'reconciliation';
    case Published = 'published';
    case Measured = 'measured';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Idea => [self::Researching, self::Blocked, self::Failed, self::Cancelled],
            self::Researching => [self::SourceVerified, self::NeedsReview, self::Blocked, self::Failed, self::Cancelled],
            self::SourceVerified => [self::Scripted, self::Blocked, self::Failed, self::Cancelled],
            self::Scripted => [self::Producing, self::NeedsReview, self::Blocked, self::Failed, self::Cancelled],
            self::Producing => [self::QualityCheck, self::Blocked, self::Failed, self::Cancelled],
            // quality_check -> producing is the "retry" edge.
            self::QualityCheck => [self::Ready, self::Producing, self::NeedsReview, self::Blocked, self::Failed, self::Cancelled],
            self::NeedsReview => [self::Ready, self::Producing, self::Blocked, self::Cancelled],
            self::Ready => [self::Scheduling, self::NeedsReview, self::Blocked, self::Cancelled],
            self::Scheduling => [self::Scheduled, self::PublishUnknown, self::Blocked, self::Failed],
            self::Scheduled => [self::Published, self::PublishUnknown, self::Failed, self::Cancelled],
            // After a timeout we must reconcile with the provider; never retry blindly.
            self::PublishUnknown => [self::Reconciliation],
            self::Reconciliation => [self::Scheduled, self::Published, self::Failed],
            self::Published => [self::Measured],
            self::Blocked => [self::NeedsReview, self::Cancelled],
            self::Failed => [self::Researching, self::Producing, self::Cancelled],
            self::Measured, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Statuses that must carry a human-readable reason. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Blocked, self::Failed, self::NeedsReview], true);
    }
}
