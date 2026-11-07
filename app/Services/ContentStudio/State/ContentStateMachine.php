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
    public function __construct(private readonly ContentEventLogger $events) {}

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
