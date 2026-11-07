<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentEvent;
use App\Models\ContentStudio\ContentItem;
use App\Services\ContentStudio\State\ContentStateMachine;
use App\Services\ContentStudio\State\InvalidContentTransition;
use App\Services\ContentStudio\State\StaleContentItem;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class ContentStateMachineTest extends TestCase
{
    use UsesContentStudioDatabase;

    private function item(): ContentItem
    {
        return ContentItem::create([
            'channel_id' => ContentChannel::default()->id,
            'slot' => 'news',
            'content_type' => 'carousel',
            'title' => 'Test',
        ]);
    }

    public function test_valid_transition_bumps_version_and_writes_an_event(): void
    {
        $item = $this->item();
        $machine = app(ContentStateMachine::class);

        $item = $machine->transition($item, ContentStatus::Researching, actor: 'tester');

        $this->assertSame(ContentStatus::Researching, $item->status);
        $this->assertSame(1, $item->lock_version);
        $event = ContentEvent::query()->where('action', 'content.transition')->sole();
        $this->assertSame('tester', $event->actor);
        $this->assertSame(['from' => 'idea', 'to' => 'researching', 'reason' => null], $event->payload_redacted);
    }

    public function test_invalid_transition_is_rejected_and_nothing_changes(): void
    {
        $item = $this->item();

        $this->expectException(InvalidContentTransition::class);
        try {
            app(ContentStateMachine::class)->transition($item, ContentStatus::Published);
        } finally {
            $this->assertSame(ContentStatus::Idea, $item->fresh()->status);
            $this->assertSame(0, ContentEvent::count());
        }
    }

    public function test_blocked_requires_a_reason_and_stores_it(): void
    {
        $machine = app(ContentStateMachine::class);
        $item = $this->item();

        try {
            $machine->transition($item, ContentStatus::Blocked);
            $this->fail('reason should be required');
        } catch (InvalidContentTransition) {
            // expected
        }

        $item = $machine->transition($item, ContentStatus::Blocked, 'rights_unverified');
        $this->assertSame('rights_unverified', $item->blocked_reason);

        $item = $machine->transition($item, ContentStatus::NeedsReview, 'admin looked at it');
        $item = $machine->transition($item, ContentStatus::Ready);
        $this->assertNull($item->blocked_reason, 'reason is cleared once the item moves on');
    }

    public function test_stale_copy_cannot_overwrite_a_newer_transition(): void
    {
        $machine = app(ContentStateMachine::class);
        $a = $this->item();
        $b = ContentItem::find($a->id);

        $machine->transition($a, ContentStatus::Researching);

        $this->expectException(StaleContentItem::class);
        $machine->transition($b, ContentStatus::Cancelled);
    }

    public function test_status_cannot_be_mass_assigned(): void
    {
        $item = $this->item();
        $item->fill(['status' => 'published', 'lock_version' => 99]);
        $item->save();

        $this->assertSame(ContentStatus::Idea, $item->fresh()->status);
        $this->assertSame(0, $item->fresh()->lock_version);
    }
}
