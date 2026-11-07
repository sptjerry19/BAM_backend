<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\RightsStatus;
use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPlan;
use App\Models\ContentStudio\ContentSource;
use App\Services\ContentStudio\Ops\ContentEventLogger;
use Illuminate\Database\QueryException;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class SchemaConstraintsTest extends TestCase
{
    use UsesContentStudioDatabase;

    public function test_one_plan_per_channel_and_date(): void
    {
        $channel = ContentChannel::default();
        ContentPlan::create(['channel_id' => $channel->id, 'plan_date' => '2026-11-08']);

        $this->expectException(QueryException::class);
        ContentPlan::create(['channel_id' => $channel->id, 'plan_date' => '2026-11-08']);
    }

    public function test_default_channel_is_created_once(): void
    {
        $this->assertSame(ContentChannel::default()->id, ContentChannel::default()->id);
        $this->assertSame(1, ContentChannel::count());
    }

    public function test_fingerprint_is_unique_per_channel_but_null_is_allowed_many_times(): void
    {
        $channel = ContentChannel::default();
        $base = ['channel_id' => $channel->id, 'slot' => 'news', 'content_type' => 'carousel'];

        ContentItem::create($base);
        ContentItem::create($base); // both NULL fingerprint: fine
        ContentItem::create($base + ['fingerprint' => 'abc']);

        $this->expectException(QueryException::class);
        ContentItem::create($base + ['fingerprint' => 'abc']);
    }

    public function test_same_source_url_cannot_be_stored_twice(): void
    {
        $url = 'https://example.com/video/1';
        $row = ['platform' => 'tiktok', 'canonical_url' => $url, 'url_hash' => ContentSource::hashUrl($url)];
        ContentSource::create($row);

        $this->expectException(QueryException::class);
        ContentSource::create($row);
    }

    public function test_new_sources_default_to_rights_unverified_and_are_not_publishable(): void
    {
        $source = ContentSource::create([
            'platform' => 'douyin', 'canonical_url' => 'https://example.com/x', 'url_hash' => ContentSource::hashUrl('https://example.com/x'),
        ]);

        $this->assertSame(RightsStatus::RightsUnverified, $source->fresh()->rights_status);
        $this->assertFalse($source->fresh()->rights_status->allowsPublishing());
    }

    public function test_rights_status_cannot_be_mass_assigned(): void
    {
        $source = ContentSource::create([
            'platform' => 'douyin', 'canonical_url' => 'https://example.com/y', 'url_hash' => ContentSource::hashUrl('https://example.com/y'),
            'rights_status' => 'licensed',
        ]);

        $this->assertSame(RightsStatus::RightsUnverified, $source->fresh()->rights_status);
    }

    public function test_event_logger_redacts_secrets_recursively(): void
    {
        $event = app(ContentEventLogger::class)->log('test', payload: [
            'ok' => 'visible',
            'api_key' => 'sk-123',
            'nested' => ['Authorization' => 'Bearer x', 'fine' => 1],
        ]);

        $this->assertSame(
            ['ok' => 'visible', 'api_key' => '[redacted]', 'nested' => ['Authorization' => '[redacted]', 'fine' => 1]],
            $event->fresh()->payload_redacted,
        );
    }
}
