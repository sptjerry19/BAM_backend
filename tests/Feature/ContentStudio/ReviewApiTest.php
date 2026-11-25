<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\ContentStudio\ContentStatus;
use App\Enums\ContentStudio\RunStatus;
use App\Models\ContentStudio\ContentEvent;
use App\Models\ContentStudio\ContentItem;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Models\User;
use App\Services\ContentStudio\Ops\AutomationGate;
use App\Services\ContentStudio\Pipeline\ContentPipelineStarter;
use App\Services\ContentStudio\Render\CarouselProducer;
use App\Services\ContentStudio\State\ContentStateMachine;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MakesContentItems;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class ReviewApiTest extends TestCase
{
    use MakesContentItems;
    use UsesContentStudioDatabase;

    private const BASE = '/api/admin/content-studio/contents/';

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

    /** A rendered item waiting for review because of one waivable finding. */
    private function reviewItem(): ContentItem
    {
        $item = $this->cleanItem(over: ['caption' => 'Đây là mẹo tốt nhất cho người mới chơi cầu lông.']);
        app(ContentPipelineStarter::class)->start($item);
        $item->refresh();
        $this->assertSame(ContentStatus::NeedsReview, $item->status);

        return $item;
    }

    private function approve(ContentItem $item, array $over = []): TestResponse
    {
        return $this->asAdmin()->postJson(self::BASE.$item->id.'/approve', $over + [
            'lock_version' => $item->lock_version, 'render_version' => 1, 'accepted' => ['claims'], 'reason' => 'Đã đọc, chấp nhận được',
        ]);
    }

    public function test_every_endpoint_requires_an_admin(): void
    {
        $item = $this->reviewItem();
        $member = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => 'test-password', 'role' => 'user']);
        $calls = [
            ['getJson', '/review'], ['getJson', '/slides/1'], ['postJson', '/approve'], ['postJson', '/retry'], ['postJson', '/cancel'],
        ];

        // anonymous calls first: once a token was used, the JWT guard remembers it
        foreach ($calls as [$method, $path]) {
            $this->{$method}(self::BASE.$item->id.$path)->assertUnauthorized();
        }
        foreach ($calls as [$method, $path]) {
            auth('api')->forgetUser();
            $this->withToken(auth('api')->login($member))->{$method}(self::BASE.$item->id.$path)->assertForbidden();
        }
        $this->assertSame(ContentStatus::NeedsReview, $item->fresh()->status);
    }

    public function test_the_review_screen_gets_text_images_findings_and_possible_actions(): void
    {
        $item = $this->reviewItem();

        $res = $this->asAdmin()->getJson(self::BASE.$item->id.'/review')->assertOk();

        $res->assertJsonPath('data.item.status', 'needs_review')
            ->assertJsonPath('data.item.lock_version', $item->lock_version)
            ->assertJsonPath('data.render.version', 1)
            ->assertJsonPath('data.render.images', [1, 2, 3, 4, 5])
            ->assertJsonPath('data.qa.verdict', 'needs_review')
            ->assertJsonPath('data.qa.failed', ['claims'])
            ->assertJsonPath('data.actions.approve.available', true)
            ->assertJsonPath('data.actions.retry.available', false)
            ->assertJsonPath('data.actions.cancel.available', true);
        $this->assertStringContainsString('#caulong', $res->json('data.item.final_caption'));
        $this->assertCount(5, $res->json('data.slides'));
    }

    public function test_a_slide_is_served_as_the_png_of_the_current_render(): void
    {
        $item = $this->reviewItem();

        $res = $this->asAdmin()->get(self::BASE.$item->id.'/slides/2')->assertOk()->assertHeader('Content-Type', 'image/png');

        $manifest = app(CarouselProducer::class)->readManifest($item, 1);
        $this->assertSame($manifest['slides'][1]['sha256'], hash('sha256', $res->getContent()));
        $this->asAdmin()->getJson(self::BASE.$item->id.'/slides/6')->assertNotFound();
        $this->asAdmin()->getJson(self::BASE.$item->id.'/slides/0')->assertNotFound();
    }

    public function test_approving_makes_the_item_ready_exports_it_and_records_who(): void
    {
        $item = $this->reviewItem();

        $res = $this->approve($item)->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.export_error', null);

        $this->assertSame(ContentStatus::Ready, $item->fresh()->status);
        Storage::disk('local')->assertExists($res->json('data.export_directory').'/caption.txt');
        $event = ContentEvent::where('action', 'content.approved')->sole();
        $this->assertSame('admin:'.$this->admin->id, $event->actor);
        $this->assertSame(['claims'], $event->payload_redacted['accepted']);
    }

    public function test_approval_needs_a_reason_and_every_finding_the_reviewer_saw(): void
    {
        $item = $this->reviewItem();

        $this->approve($item, ['reason' => '  '])->assertStatus(422)->assertJsonPath('data.code', 'reason_required');
        $this->approve($item, ['accepted' => []])->assertStatus(409)->assertJsonPath('data.code', 'findings_changed')
            ->assertJsonPath('data.findings.0.code', 'claims');

        $this->assertSame(ContentStatus::NeedsReview, $item->fresh()->status);
    }

    public function test_a_decision_about_an_outdated_view_is_refused(): void
    {
        $item = $this->reviewItem();

        $this->approve($item, ['lock_version' => $item->lock_version - 1])->assertStatus(409)->assertJsonPath('data.code', 'item_changed');
        $this->approve($item, ['render_version' => 2])->assertStatus(409);
        $this->asAdmin()->postJson(self::BASE.$item->id.'/cancel', ['lock_version' => 0, 'reason' => 'x'])->assertStatus(409);

        $this->assertSame(ContentStatus::NeedsReview, $item->fresh()->status);
    }

    public function test_mock_content_and_unwaivable_problems_cannot_be_approved(): void
    {
        $item = $this->reviewItem();
        $item->forceFill(['brief_json' => ['mock' => true] + $item->brief_json])->save();

        $this->approve($item)->assertStatus(422)->assertJsonPath('data.code', 'mock_content');
        $this->asAdmin()->getJson(self::BASE.$item->id.'/review')->assertJsonPath('data.actions.approve', ['available' => false, 'reason' => 'mock_content']);

        $item->forceFill(['brief_json' => ['mock' => false] + $item->brief_json])->save();
        $path = app(CarouselProducer::class)->readManifest($item, 1)['slides'][0]['path'];
        Storage::disk('local')->put($path, 'broken');

        $this->approve($item)->assertStatus(422)->assertJsonPath('data.code', 'blocking_findings');
        $this->assertSame(ContentStatus::NeedsReview, $item->fresh()->status);
    }

    public function test_retry_starts_a_new_run_only_for_a_failed_item(): void
    {
        $ready = $this->cleanItem();
        app(ContentPipelineStarter::class)->start($ready);
        $this->asAdmin()->postJson(self::BASE.$ready->id.'/retry')->assertStatus(422)->assertJsonPath('data.code', 'not_failed');

        $item = $this->cleanItem();
        app(ContentStateMachine::class)->transition($item, ContentStatus::Failed, 'render crashed');
        $this->asAdmin()->postJson(self::BASE.$item->id.'/retry')->assertOk()->assertJsonPath('data.paused', false);

        $this->assertSame(ContentStatus::Ready, $item->fresh()->status);
    }

    public function test_retry_while_paused_is_held_and_says_so(): void
    {
        $item = $this->cleanItem();
        app(ContentStateMachine::class)->transition($item, ContentStatus::Failed, 'render crashed');
        app(AutomationGate::class)->pause('test');

        $this->asAdmin()->postJson(self::BASE.$item->id.'/retry')->assertOk()->assertJsonPath('data.paused', true);

        $this->assertSame(ContentStatus::Producing, $item->fresh()->status);
    }

    public function test_cancel_stops_the_item_and_closes_its_open_runs(): void
    {
        $item = $this->cleanItem();
        app(AutomationGate::class)->pause('test'); // keeps the run open
        $run = app(ContentPipelineStarter::class)->start($item);

        $this->asAdmin()->postJson(self::BASE.$item->id.'/cancel', ['lock_version' => 0])->assertStatus(422);
        $this->asAdmin()->postJson(self::BASE.$item->id.'/cancel', ['lock_version' => 0, 'reason' => 'Trùng đề tài'])->assertOk();

        $this->assertSame(ContentStatus::Cancelled, $item->fresh()->status);
        $this->assertSame(RunStatus::Cancelled, ContentPipelineRun::find($run->id)->status);
        $this->assertSame('Trùng đề tài', ContentEvent::where('action', 'content.cancelled')->sole()->payload_redacted['reason']);
    }

    public function test_finished_and_scheduled_items_cannot_be_cancelled(): void
    {
        foreach ([ContentStatus::Cancelled, ContentStatus::Published, ContentStatus::Scheduled] as $status) {
            $item = $this->cleanItem();
            $item->forceFill(['status' => $status])->save();

            $this->asAdmin()->postJson(self::BASE.$item->id.'/cancel', ['lock_version' => 0, 'reason' => 'x'])
                ->assertStatus(422)->assertJsonPath('data.code', 'not_cancellable');
            $this->asAdmin()->getJson(self::BASE.$item->id.'/review')->assertJsonPath('data.actions.cancel.available', false);
        }
    }
}
