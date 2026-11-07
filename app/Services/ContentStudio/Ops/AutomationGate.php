<?php

namespace App\Services\ContentStudio\Ops;

use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentSetting;

/**
 * Kill switch. New work (new steps, new redispatches) must not start while
 * paused; a step that is already running is allowed to finish.
 */
final class AutomationGate
{
    public const SETTING_KEY = 'automation_paused';

    public function __construct(private readonly ContentEventLogger $events) {}

    public function isPaused(): bool
    {
        if (config('content-studio.automation_paused')) {
            return true;
        }

        $channel = ContentChannel::default();
        $setting = ContentSetting::query()
            ->where('channel_id', $channel->id)
            ->where('key', self::SETTING_KEY)
            ->first();

        return (bool) ($setting?->value_json['paused'] ?? false);
    }

    public function pause(string $actor, ?string $reason = null): void
    {
        $this->set(true, $actor, $reason);
    }

    public function resume(string $actor): void
    {
        $this->set(false, $actor, null);
    }

    private function set(bool $paused, string $actor, ?string $reason): void
    {
        $channel = ContentChannel::default();
        ContentSetting::updateOrCreate(
            ['channel_id' => $channel->id, 'key' => self::SETTING_KEY],
            ['value_json' => ['paused' => $paused, 'reason' => $reason, 'by' => $actor, 'at' => now()->toIso8601String()]],
        );
        $this->events->log($paused ? 'automation.paused' : 'automation.resumed', 'channel', $channel->id, ['reason' => $reason], $actor);
    }
}
