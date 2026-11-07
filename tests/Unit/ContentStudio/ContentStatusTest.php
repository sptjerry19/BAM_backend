<?php

namespace Tests\Unit\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Enums\ContentStudio\RightsStatus;
use PHPUnit\Framework\TestCase;

class ContentStatusTest extends TestCase
{
    public function test_happy_path_is_allowed_end_to_end(): void
    {
        $path = [
            ContentStatus::Idea, ContentStatus::Researching, ContentStatus::SourceVerified,
            ContentStatus::Scripted, ContentStatus::Producing, ContentStatus::QualityCheck,
            ContentStatus::Ready, ContentStatus::Scheduling, ContentStatus::Scheduled,
            ContentStatus::Published, ContentStatus::Measured,
        ];

        foreach (array_slice($path, 0, -1) as $i => $from) {
            $this->assertTrue($from->canTransitionTo($path[$i + 1]), "{$from->value} -> {$path[$i + 1]->value}");
        }
    }

    public function test_cannot_publish_straight_from_scripted_or_producing(): void
    {
        foreach ([ContentStatus::Scripted, ContentStatus::Producing] as $from) {
            $this->assertFalse($from->canTransitionTo(ContentStatus::Scheduling));
            $this->assertFalse($from->canTransitionTo(ContentStatus::Scheduled));
            $this->assertFalse($from->canTransitionTo(ContentStatus::Published));
        }
    }

    public function test_publish_unknown_can_only_go_to_reconciliation(): void
    {
        $this->assertSame([ContentStatus::Reconciliation], ContentStatus::PublishUnknown->allowedNext());
    }

    public function test_scheduled_is_not_published(): void
    {
        $this->assertFalse(ContentStatus::Scheduling->canTransitionTo(ContentStatus::Published));
    }

    public function test_quality_check_can_go_back_to_producing_for_retry(): void
    {
        $this->assertTrue(ContentStatus::QualityCheck->canTransitionTo(ContentStatus::Producing));
    }

    public function test_terminal_states(): void
    {
        $this->assertTrue(ContentStatus::Measured->isTerminal());
        $this->assertTrue(ContentStatus::Cancelled->isTerminal());
        $this->assertFalse(ContentStatus::Failed->isTerminal());
    }

    public function test_blocked_failed_and_needs_review_require_a_reason(): void
    {
        foreach ([ContentStatus::Blocked, ContentStatus::Failed, ContentStatus::NeedsReview] as $s) {
            $this->assertTrue($s->requiresReason());
        }
        $this->assertFalse(ContentStatus::Ready->requiresReason());
    }

    public function test_every_status_only_points_at_known_statuses(): void
    {
        foreach (ContentStatus::cases() as $status) {
            foreach ($status->allowedNext() as $next) {
                $this->assertInstanceOf(ContentStatus::class, $next);
                $this->assertNotSame($status, $next);
            }
        }
    }

    public function test_only_owned_licensed_or_permission_granted_may_be_published(): void
    {
        $allowed = array_filter(RightsStatus::cases(), fn (RightsStatus $r) => $r->allowsPublishing());

        $this->assertEqualsCanonicalizing(
            [RightsStatus::Owned, RightsStatus::Licensed, RightsStatus::PermissionGranted],
            array_values($allowed),
        );
        $this->assertFalse(RightsStatus::ReferenceOnly->allowsPublishing());
        $this->assertFalse(RightsStatus::RightsUnverified->allowsPublishing());
        $this->assertFalse(RightsStatus::Expired->allowsPublishing());
    }
}
