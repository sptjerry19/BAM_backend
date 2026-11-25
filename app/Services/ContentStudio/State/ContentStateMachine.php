<?php

namespace App\Services\ContentStudio\State;

use App\Enums\ContentStudio\ContentStatus;
use App\Models\ContentStudio\ContentItem;
use App\Services\ContentStudio\Ops\ContentEventLogger;

/**
 * Single entry point for changing ContentItem::$status.
 *
 * Optimistic locking: the UPDATE only matches when both the status and the
 * lock_version are still what we read, so two workers cannot both win.
 */
final class ContentStateMachine
{
    /** The happy path, in order. advanceTo() walks along it. */
    private const FORWARD_PATH = [
        ContentStatus::Idea, ContentStatus::Researching, ContentStatus::SourceVerified, ContentStatus::Scripted,
        ContentStatus::Producing, ContentStatus::QualityCheck, ContentStatus::Ready,
    ];

    public function __construct(private readonly ContentEventLogger $events) {}

    /**
     * Move forward along the happy path up to $target, one validated transition at a time.
     * Idempotent: an item that is already at or past $target (or off the path, e.g. blocked)
     * is returned unchanged, so a re-delivered job cannot move it backwards.
     */
    public function advanceTo(ContentItem $item, ContentStatus $target, string $actor = 'system', ?string $traceId = null): ContentItem
    {
        $from = array_search($item->status, self::FORWARD_PATH, true);
        $to = array_search($target, self::FORWARD_PATH, true);
        if ($from === false || $to === false || $from >= $to) {
            return $item;
        }

        for ($i = $from + 1; $i <= $to; $i++) {
            $item = $this->transition($item, self::FORWARD_PATH[$i], null, $actor, $traceId);
        }

        return $item;
    }

    /** Like transition(), but a no-op when the item is already in $status (re-delivered jobs). */
    public function settle(ContentItem $item, ContentStatus $status, ?string $reason = null, string $actor = 'system', ?string $traceId = null): ContentItem
    {
        return $item->status === $status ? $item : $this->transition($item, $status, $reason, $actor, $traceId);
    }

    public function transition(
        ContentItem $item,
        ContentStatus $to,
        ?string $reason = null,
        string $actor = 'system',
        ?string $traceId = null,
    ): ContentItem {
        $from = $item->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidContentTransition::between($from, $to);
        }
        if ($to->requiresReason() && blank($reason)) {
            throw InvalidContentTransition::reasonRequired($to);
        }

        $updated = ContentItem::query()
            ->whereKey($item->getKey())
            ->where('status', $from->value)
            ->where('lock_version', $item->lock_version)
            ->update([
                'status' => $to->value,
                'blocked_reason' => $to->requiresReason() ? $reason : null,
                'lock_version' => $item->lock_version + 1,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw StaleContentItem::forItem($item->getKey());
        }

        $this->events->log(
            'content.transition',
            'content_item',
            $item->getKey(),
            ['from' => $from->value, 'to' => $to->value, 'reason' => $reason],
            $actor,
            $traceId,
        );

        return $item->refresh();
    }
}
