<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Jobs\ContentStudio\SmokeStep;
use App\Models\ContentStudio\ContentChannel;
use App\Models\ContentStudio\ContentEvent;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Models\ContentStudio\ContentSource;
use App\Models\User;
use App\Services\ContentStudio\Ops\AutomationGate;
use App\Services\ContentStudio\State\ContentStateMachine;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use UsesContentStudioDatabase;

    private const BASE = '/api/admin/content-studio';

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2014_10_12_000000_create_users_table', '2024_01_01_000003_create_members_table', '2026_08_03_000004_add_role_and_member_id_to_users_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'test-password', 'role' => 'admin']);
        $this->member = User::create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'test-password', 'role' => 'user']);
        config(['content-studio.queues.connection' => 'sync']);
    }

    private function as(User $user): self
    {
        auth('api')->forgetUser();

        return $this->withToken(auth('api')->login($user));
    }

    private function item(array $attrs = []): ContentItem
    {
        return ContentItem::create($attrs + ['channel_id' => ContentChannel::default()->id, 'slot' => 'news', 'content_type' => 'carousel', 'title' => 'T']);
    }

    public function test_every_endpoint_requires_an_admin(): void
    {
        $item = $this->item();
        $paths = ['/overview', '/contents', "/contents/{$item->id}", '/sources', '/pipeline-runs', '/settings'];

        foreach ($paths as $path) {
            $this->getJson(self::BASE.$path)->assertUnauthorized();
        }
        $this->postJson(self::BASE.'/automation/pause')->assertUnauthorized();

        foreach ($paths as $path) {
            $this->as($this->member)->getJson(self::BASE.$path)->assertForbidden();
        }
        $this->as($this->member)->postJson(self::BASE.'/automation/pause')->assertForbidden();
        $this->assertFalse(app(AutomationGate::class)->isPaused(), 'a non-admin must not be able to pause');
    }

    public function test_overview_reports_flags_counts_and_blockers(): void
    {
        $blocked = app(ContentStateMachine::class)->transition($this->item(['title' => 'Stuck']), ContentStatus::Blocked, 'rights_unverified');
        $this->item();

        $res = $this->as($this->admin)->getJson(self::BASE.'/overview')->assertOk();

        $res->assertJsonPath('data.flags.enabled', false)   // off by default
            ->assertJsonPath('data.flags.autopublish_enabled', false)
            ->assertJsonPath('data.flags.paused', false)
            ->assertJsonPath('data.items_by_status.idea', 1)
            ->assertJsonPath('data.items_by_status.blocked', 1)
            ->assertJsonPath('data.items_created_today', 2)
            ->assertJsonPath('data.blockers.0.id', $blocked->id)
            ->assertJsonPath('data.blockers.0.blocked_reason', 'rights_unverified');
    }

    public function test_contents_can_be_filtered_and_paginated_and_bad_filters_are_rejected(): void
    {
        $this->item(['slot' => 'news']);
        $this->item(['slot' => 'equipment']);
        $this->item(['slot' => 'equipment']);

        $this->as($this->admin)->getJson(self::BASE.'/contents?slot=equipment')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->as($this->admin)->getJson(self::BASE.'/contents?slot=equipment&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.has_more_pages', true);
        $this->as($this->admin)->getJson(self::BASE.'/contents?status=idea')->assertOk()->assertJsonCount(3, 'data');
        $this->as($this->admin)->getJson(self::BASE.'/contents?status=published')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->admin)->getJson(self::BASE.'/contents?status=nonsense')->assertStatus(422);
        $this->as($this->admin)->getJson(self::BASE.'/contents?per_page=9999')->assertStatus(422);
    }

    public function test_content_detail_shows_sources_with_publishable_flag_and_history(): void
    {
        $item = $this->item();
        $source = ContentSource::create(['platform' => 'tiktok', 'bucket' => 'reup', 'canonical_url' => 'https://example.com/v', 'url_hash' => ContentSource::hashUrl('https://example.com/v')]);
        $item->sources()->attach($source->id, ['usage' => 'reference']);
        app(ContentStateMachine::class)->transition($item, ContentStatus::Researching, actor: 'tester');

        $res = $this->as($this->admin)->getJson(self::BASE."/contents/{$item->id}")->assertOk();

        $res->assertJsonPath('data.sources.0.rights_status', 'rights_unverified')
            ->assertJsonPath('data.sources.0.publishable', false)
            ->assertJsonPath('data.events.0.action', 'content.transition')
            ->assertJsonPath('data.events.0.actor', 'tester');
        $this->as($this->admin)->getJson(self::BASE.'/contents/999999')->assertNotFound();
    }

    public function test_sources_endpoint_filters_by_rights_status(): void
    {
        $a = ContentSource::create(['platform' => 'tiktok', 'bucket' => 'reup', 'canonical_url' => 'https://e.test/a', 'url_hash' => ContentSource::hashUrl('https://e.test/a')]);
        $b = ContentSource::create(['platform' => 'youtube', 'bucket' => 'news', 'canonical_url' => 'https://e.test/b', 'url_hash' => ContentSource::hashUrl('https://e.test/b')]);
        $b->forceFill(['rights_status' => 'licensed'])->save();

        $this->as($this->admin)->getJson(self::BASE.'/sources?rights_status=licensed')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $b->id)->assertJsonPath('data.0.publishable', true);
        $this->as($this->admin)->getJson(self::BASE.'/sources?bucket=reup')
            ->assertOk()->assertJsonPath('data.0.id', $a->id)->assertJsonPath('data.0.publishable', false);
        $this->as($this->admin)->getJson(self::BASE.'/sources?rights_status=bogus')->assertStatus(422);
    }

    public function test_pipeline_run_detail_hides_the_internal_resume_descriptor(): void
    {
        $run = ContentPipelineRun::start(null, 'system.smoke');
        $run->forceFill(['resume_job_json' => ['class' => SmokeStep::class, 'args' => [$run->id]]])->save();

        $this->as($this->admin)->getJson(self::BASE."/pipeline-runs/{$run->id}")
            ->assertOk()->assertJsonPath('data.pipeline', 'system.smoke')->assertJsonMissingPath('data.resume_job_json');
        $this->as($this->admin)->getJson(self::BASE.'/pipeline-runs')->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->admin)->getJson(self::BASE.'/pipeline-runs/not-a-uuid')->assertNotFound();
    }

    public function test_pause_is_audited_and_resume_continues_held_runs(): void
    {
        $run = ContentPipelineRun::start(null, 'system.smoke');
        $run->forceFill([
            'resume_job_json' => ['class' => SmokeStep::class, 'args' => [$run->id]],
            'updated_at' => now()->subHour(),
        ])->saveQuietly();

        $this->as($this->admin)->postJson(self::BASE.'/automation/pause', ['reason' => 'maintenance'])->assertOk();

        $this->assertTrue(app(AutomationGate::class)->isPaused());
        $event = ContentEvent::where('action', 'automation.paused')->sole();
        $this->assertSame('admin:'.$this->admin->id, $event->actor);
        $this->assertSame('maintenance', $event->payload_redacted['reason']);
        $this->as($this->admin)->getJson(self::BASE.'/overview')->assertJsonPath('data.flags.paused', true);

        // While paused a step does not start...
        $this->artisan('content:recover --boot')->expectsOutputToContain('automation paused')->assertExitCode(0);
        $this->assertSame(0, $run->steps()->count());

        // ...and resume immediately continues the held run.
        $this->as($this->admin)->postJson(self::BASE.'/automation/resume')
            ->assertOk()->assertJsonPath('data.paused', false)->assertJsonPath('data.recovery.redispatched', 1);
        $this->assertFalse(app(AutomationGate::class)->isPaused());
        $this->assertSame('succeeded', $run->fresh()->status->value);
        $this->assertSame(1, ContentEvent::where('action', 'automation.resumed')->count());
    }

    public function test_environment_kill_switch_cannot_be_resumed_from_the_api(): void
    {
        config(['content-studio.automation_paused' => true]);

        $this->as($this->admin)->postJson(self::BASE.'/automation/resume')
            ->assertOk()->assertJsonPath('data.paused', true)->assertJsonPath('data.env_locked', true);
        $this->assertTrue(app(AutomationGate::class)->isPaused());
    }

    public function test_pause_reason_is_validated(): void
    {
        $this->as($this->admin)->postJson(self::BASE.'/automation/pause', ['reason' => str_repeat('x', 201)])->assertStatus(422);
    }

    public function test_settings_expose_flags_but_no_secrets(): void
    {
        $res = $this->as($this->admin)->getJson(self::BASE.'/settings')->assertOk();

        $res->assertJsonPath('data.flags.enabled', false)
            ->assertJsonPath('data.channel.handle', 'jerrybadminton')
            ->assertJsonPath('data.automation.paused', false);
        $this->assertStringNotContainsStringIgnoringCase('key', json_encode($res->json('data.flags')));
        $this->assertStringNotContainsString(env('JWT_SECRET', 'x-never'), $res->getContent());
    }
}
