<?php

namespace Tests\Feature\ContentStudio;

use App\Models\ContentStudio\ContentPlan;
use App\Models\User;
use App\Services\ContentStudio\Pipeline\ContentPipelineStarter;
use Tests\Concerns\MakesContentItems;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class CalendarApiTest extends TestCase
{
    use MakesContentItems;
    use UsesContentStudioDatabase;

    private const URL = '/api/admin/content-studio/calendar';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeStorage();
        foreach (['2014_10_12_000000_create_users_table', '2024_01_01_000003_create_members_table', '2026_08_03_000004_add_role_and_member_id_to_users_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'test-password', 'role' => 'admin']);
    }

    private function asAdmin(): self
    {
        auth('api')->forgetUser();

        return $this->withToken(auth('api')->login($this->admin));
    }

    public function test_it_requires_an_admin(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        auth('api')->forgetUser();
        $member = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => 'test-password', 'role' => 'user']);
        $this->withToken(auth('api')->login($member))->getJson(self::URL)->assertForbidden();
    }

    public function test_it_lists_planned_days_with_items_proposals_and_shortfalls(): void
    {
        $item = $this->cleanItem();
        app(ContentPipelineStarter::class)->start($item);
        $this->plan()->update(['shortfalls_json' => [['slot' => 'news', 'reason' => 'no_verified_recent_source: nothing fresh']]]);

        $res = $this->asAdmin()->getJson(self::URL.'?from=2026-11-01&to=2026-11-30')->assertOk();

        $res->assertJsonPath('data.timezone', 'Asia/Ho_Chi_Minh')
            ->assertJsonCount(1, 'data.days')
            ->assertJsonPath('data.days.0.date', '2026-11-08')
            ->assertJsonPath('data.days.0.shortfalls.0.slot', 'news')
            ->assertJsonPath('data.days.0.items.0.id', $item->id)
            ->assertJsonPath('data.days.0.items.0.status', 'ready')
            ->assertJsonPath('data.days.0.items.0.slot', 'tutorial')
            ->assertJsonPath('data.days.0.items.0.slides_rendered', 5)
            ->assertJsonPath('data.days.0.items.0.proposed_publish_at', '2026-11-08T10:30:00+00:00')
            ->assertJsonPath('data.days.0.items.0.scheduled_at', null)
            ->assertJsonPath('data.days.0.items.0.mock', false);
    }

    public function test_a_proposal_is_never_presented_as_a_schedule(): void
    {
        $this->cleanItem();

        $item = $this->asAdmin()->getJson(self::URL.'?from=2026-11-08&to=2026-11-08')->json('data.days.0.items.0');

        $this->assertNotNull($item['proposed_publish_at']);
        $this->assertNull($item['scheduled_at']);
    }

    public function test_the_range_filters_days(): void
    {
        $this->cleanItem();
        $other = ContentPlan::create(['channel_id' => $this->plan()->channel_id, 'plan_date' => '2026-12-25', 'status' => 'planned']);

        $this->asAdmin()->getJson(self::URL.'?from=2026-11-01&to=2026-11-30')->assertJsonCount(1, 'data.days');
        $this->asAdmin()->getJson(self::URL.'?from=2026-12-01&to=2026-12-31')->assertJsonCount(1, 'data.days')->assertJsonPath('data.days.0.date', $other->plan_date->toDateString());
        $this->asAdmin()->getJson(self::URL.'?from=2027-01-01&to=2027-01-31')->assertJsonCount(0, 'data.days');
    }

    public function test_blocked_and_review_items_show_why(): void
    {
        $item = $this->cleanItem('news'); // no source: blocked at verify
        app(ContentPipelineStarter::class)->start($item);

        $row = $this->asAdmin()->getJson(self::URL.'?from=2026-11-08&to=2026-11-08')->json('data.days.0.items.0');

        $this->assertSame('blocked', $row['status']);
        $this->assertStringContainsString('news_source_invalid', $row['blocked_reason']);
    }

    public function test_the_range_is_validated_and_bounded(): void
    {
        $this->asAdmin()->getJson(self::URL.'?from=08-11-2026')->assertStatus(422);
        $this->asAdmin()->getJson(self::URL.'?from=2026-11-10&to=2026-11-01')->assertStatus(422);
        $this->asAdmin()->getJson(self::URL.'?from=2026-01-01&to=2026-12-31')->assertStatus(422);
        $this->asAdmin()->getJson(self::URL)->assertOk();
    }
}
