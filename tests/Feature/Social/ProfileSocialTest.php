<?php

namespace Tests\Feature\Social;

use App\Events\Social\UserChanged;
use App\Jobs\Social\PublishOutbox;
use App\Models\Member;
use App\Models\MemberEquipment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileSocialTest extends TestCase
{
    private User $a;

    private User $b;

    private User $c;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'social_test', 'database.connections.social_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true], 'cache.default' => 'array']);
        foreach (['2014_10_12_000000_create_users_table', '2024_01_01_000003_create_members_table', '2026_08_03_000004_add_role_and_member_id_to_users_table', '2024_01_01_000002_create_products_table', '2024_01_01_000007_create_member_equipments_table', '2026_10_31_100000_create_social_profile_tables'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        foreach (['a', 'b', 'c'] as $key) {
            $member = Member::create(['name' => strtoupper($key), 'email' => $key.'@example.test', 'phone' => '0900000000', 'join_date' => '2026-10-31', 'total_cost' => 250000]);
            $this->$key = User::create(['name' => $member->name, 'email' => $member->email, 'password' => 'test-password', 'role' => 'user', 'member_id' => $member->id]);
        }
        Storage::fake('social_private');
    }

    private function asUser(User $user): self
    {
        auth('api')->forgetUser();

        return $this->withToken(auth('api')->login($user));
    }

    public function test_profile_and_equipment_privacy_and_ownership(): void
    {
        MemberEquipment::create(['member_id' => $this->a->member_id, 'name' => 'Vợt A', 'category' => 'racket', 'price' => 999999, 'notes' => 'private']);
        MemberEquipment::create(['member_id' => $this->b->member_id, 'name' => 'Vợt B', 'category' => 'racket']);
        $this->asUser($this->b)->getJson('/api/profiles/'.$this->a->id)->assertOk()->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.phone')->assertJsonMissingPath('data.total_cost');
        $this->getJson('/api/profiles/'.$this->a->id.'/equipment')->assertOk()->assertJsonPath('data.data.0.name', 'Vợt A')->assertJsonMissingPath('data.data.0.price')->assertJsonMissingPath('data.data.0.notes');
        $this->getJson('/api/members/'.$this->a->member_id.'/custom-equipments')->assertForbidden();
        $this->deleteJson('/api/custom-equipments/1')->assertForbidden();
        $this->asUser($this->a)->patchJson('/api/me/privacy', ['equipment' => 'only_me'])->assertOk();
        $this->asUser($this->b)->getJson('/api/profiles/'.$this->a->id.'/equipment')->assertForbidden();
    }

    public function test_posts_enforce_audience_friendship_and_block(): void
    {
        $id = $this->asUser($this->a)->postJson('/api/posts', ['body' => 'For friends', 'audience' => 'friends'])->assertOk()->json('data.id');
        $this->asUser($this->b)->getJson('/api/posts/'.$id)->assertNotFound();
        $this->postJson('/api/profiles/'.$this->a->id.'/relationship', ['action' => 'request'])->assertOk();
        $this->asUser($this->a)->postJson('/api/profiles/'.$this->b->id.'/relationship', ['action' => 'accept'])->assertOk();
        $this->asUser($this->b)->getJson('/api/posts/'.$id)->assertOk();
        $this->patchJson('/api/posts/'.$id, ['body' => 'bad'])->assertForbidden();
        $this->postJson('/api/profiles/'.$this->a->id.'/relationship', ['action' => 'block'])->assertOk();
        $this->getJson('/api/posts/'.$id)->assertNotFound();
        $this->getJson('/api/profiles/'.$this->a->id.'/posts')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_media_signature_does_not_bypass_changed_audience(): void
    {
        $media = $this->asUser($this->a)->post('/api/social-media', ['purpose' => 'post', 'file' => UploadedFile::fake()->image('test.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $post = $this->postJson('/api/posts', ['body' => 'Photo', 'audience' => 'public', 'media_ids' => [$media]])->assertOk()->json('data.id');
        $url = $this->asUser($this->b)->getJson('/api/posts/'.$post)->assertOk()->json('data.media.0.url');
        $this->get($url)->assertOk();
        $this->asUser($this->a)->patchJson('/api/posts/'.$post, ['audience' => 'only_me'])->assertOk();
        $this->asUser($this->b)->get($url)->assertNotFound();
        $this->get('/api/social-media/'.$media)->assertForbidden();
    }

    public function test_reaction_idempotency_and_comment_parent_scope(): void
    {
        $id = $this->asUser($this->a)->postJson('/api/posts', ['body' => 'One'])->json('data.id');
        $other = $this->postJson('/api/posts', ['body' => 'Two'])->json('data.id');
        $parent = $this->postJson('/api/posts/'.$other.'/comments', ['body' => 'Parent'])->json('data.id');
        $this->asUser($this->b)->putJson('/api/posts/'.$id.'/reaction', ['reaction' => 'like'])->assertOk();
        $this->putJson('/api/posts/'.$id.'/reaction', ['reaction' => 'love'])->assertOk();
        $this->assertSame(1, DB::table('post_reactions')->where('post_id', $id)->count());
        $this->postJson('/api/posts/'.$id.'/comments', ['body' => 'Wrong parent', 'parent_id' => $parent])->assertUnprocessable();
    }

    public function test_chat_membership_idempotency_read_cursor_and_outbox(): void
    {
        $conversation = $this->asUser($this->a)->postJson('/api/conversations', ['user_id' => $this->b->id])->assertOk()->json('data.id');
        $uuid = (string) Str::uuid();
        $id = $this->postJson('/api/conversations/'.$conversation.'/messages', ['body' => 'Hello', 'client_message_id' => $uuid])->assertOk()->json('data.id');
        $this->postJson('/api/conversations/'.$conversation.'/messages', ['body' => 'Hello', 'client_message_id' => $uuid])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('messages')->count());
        $this->assertSame(2, DB::table('social_outbox')->count());
        $this->asUser($this->c)->getJson('/api/conversations/'.$conversation.'/messages')->assertForbidden();
        $this->asUser($this->b)->putJson('/api/conversations/'.$conversation.'/accept')->assertOk();
        $this->putJson('/api/conversations/'.$conversation.'/read', ['message_id' => $id])->assertOk();
        $this->assertSame($id, DB::table('conversation_participants')->where('conversation_id', $conversation)->where('user_id', $this->b->id)->value('last_read_id'));
        $this->postJson('/api/profiles/'.$this->a->id.'/relationship', ['action' => 'block'])->assertOk();
        $this->postJson('/api/conversations/'.$conversation.'/messages', ['body' => 'No', 'client_message_id' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_broadcast_channel_auth(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        $this->asUser($this->a)->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-users.'.$this->b->id])->assertForbidden();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-users.'.$this->a->id])->assertOk();
    }

    public function test_story_expiry_and_owner_only_views(): void
    {
        $media = $this->asUser($this->a)->post('/api/social-media', ['purpose' => 'story', 'file' => UploadedFile::fake()->image('story.jpg')], ['Accept' => 'application/json'])->json('data.id');
        $id = $this->postJson('/api/stories', ['audience' => 'public', 'media_ids' => [$media]])->assertOk()->json('data.id');
        $this->asUser($this->b)->putJson('/api/stories/'.$id.'/view')->assertOk();
        $this->getJson('/api/stories/'.$id.'/views')->assertForbidden();
        DB::table('stories')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/profiles/'.$this->a->id.'/stories')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/stories/'.$id.'/view')->assertNotFound();
    }

    public function test_album_cannot_expand_private_post_access_and_tags_need_approval(): void
    {
        $media = $this->asUser($this->a)->post('/api/social-media', ['purpose' => 'post', 'file' => UploadedFile::fake()->image('album.jpg')], ['Accept' => 'application/json'])->json('data.id');
        $post = $this->postJson('/api/posts', ['body' => 'Private album photo', 'audience' => 'only_me', 'media_ids' => [$media]])->json('data.id');
        $album = $this->postJson('/api/albums', ['name' => 'Album', 'audience' => 'public', 'media_ids' => [$media]])->assertOk()->json('data.id');
        $this->postJson('/api/posts/'.$post.'/tags', ['user_id' => $this->b->id])->assertOk();
        $this->asUser($this->b)->getJson('/api/albums/'.$album)->assertOk()->assertJsonCount(0, 'data.data');
        $this->patchJson('/api/tags/1', ['status' => 'approved'])->assertOk();
        $this->getJson('/api/profiles/'.$this->b->id.'/tagged')->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson('/api/social-media/'.$media.'/access')->assertNotFound();
    }

    public function test_share_hides_original_after_audience_changes_and_trash_restores(): void
    {
        $original = $this->asUser($this->a)->postJson('/api/posts', ['body' => 'Original'])->json('data.id');
        $share = $this->asUser($this->b)->postJson('/api/posts', ['shared_post_id' => $original])->assertOk()->json('data.id');
        $this->asUser($this->a)->patchJson('/api/posts/'.$original, ['audience' => 'only_me'])->assertOk();
        $this->asUser($this->b)->getJson('/api/posts/'.$share)->assertOk()->assertJsonPath('data.shared.available', false)->assertJsonMissingPath('data.shared.body');
        $this->deleteJson('/api/posts/'.$share)->assertOk();
        $this->getJson('/api/posts/'.$share)->assertNotFound();
        $this->putJson('/api/posts/'.$share.'/restore')->assertOk();
        $this->getJson('/api/posts/'.$share)->assertOk();
    }

    public function test_guest_preview_hides_owners_private_data_and_cursor_is_stable(): void
    {
        $this->asUser($this->a)->getJson('/api/profiles/'.$this->a->id.'?as_guest=1')->assertOk()->assertJsonPath('data.is_owner', false)->assertJsonMissingPath('data.email');
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/posts', ['body' => 'Post '.$i])->assertOk();
        }
        $first = $this->getJson('/api/profiles/'.$this->a->id.'/posts?per_page=2')->assertOk()->json('data');
        $this->postJson('/api/posts', ['body' => 'New post'])->assertOk();
        $next = $this->getJson('/api/profiles/'.$this->a->id.'/posts?per_page=2&cursor='.urlencode($first['next_cursor']))->assertOk()->json('data');
        $this->assertSame([], array_intersect(array_column($first['data'], 'id'), array_column($next['data'], 'id')));
        $this->assertCount(1, $next['data']);
    }

    public function test_outbox_is_retried_until_success_and_only_marks_after_broadcast(): void
    {
        Event::fake([UserChanged::class]);
        $id = DB::table('social_outbox')->insertGetId(['user_id' => $this->a->id, 'event' => 'MessageSent', 'payload' => json_encode(['message_id' => 1]), 'created_at' => now(), 'updated_at' => now()]);
        $job = new PublishOutbox($id);
        $job->handle();
        $job->handle();
        Event::assertDispatchedTimes(UserChanged::class, 1);
        $this->assertNotNull(DB::table('social_outbox')->where('id', $id)->value('published_at'));
    }

    public function test_custom_equipment_image_uses_private_disk_and_respects_changed_privacy(): void
    {
        $file = UploadedFile::fake()->image('racket.jpg');
        $created = $this->asUser($this->a)->postJson('/api/members/'.$this->a->member_id.'/custom-equipments', ['name' => 'Vợt mới', 'category' => 'racket', 'image' => 'data:image/jpeg;base64,'.base64_encode(file_get_contents($file->getRealPath()))])->assertCreated();
        $this->assertSame(1, DB::table('media_assets')->where('purpose', 'equipment')->count());
        $url = $this->asUser($this->b)->getJson('/api/profiles/'.$this->a->id.'/equipment')->assertOk()->json('data.data.0.image');
        $this->get($url)->assertOk();
        $this->asUser($this->a)->patchJson('/api/me/privacy', ['equipment' => 'only_me'])->assertOk();
        $this->asUser($this->b)->get($url)->assertNotFound();
    }

    public function test_hidden_post_keeps_original_direct_access_and_cannot_be_emptied(): void
    {
        $post = $this->asUser($this->a)->postJson('/api/posts', ['body' => 'Keep visibility'])->json('data.id');
        $this->patchJson('/api/posts/'.$post, ['body' => null])->assertUnprocessable();
        $this->patchJson('/api/posts/'.$post, ['hidden' => true])->assertOk();
        $this->asUser($this->b)->getJson('/api/posts/'.$post)->assertOk()->assertJsonPath('data.body', 'Keep visibility');
        $this->getJson('/api/profiles/'.$this->a->id.'/posts')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_removed_media_cannot_be_reused_or_read_using_old_url(): void
    {
        $media = $this->asUser($this->a)->post('/api/social-media', ['purpose' => 'post', 'file' => UploadedFile::fake()->image('remove.jpg')], ['Accept' => 'application/json'])->json('data.id');
        $post = $this->postJson('/api/posts', ['body' => 'Keep body', 'media_ids' => [$media]])->json('data');
        $this->patchJson('/api/posts/'.$post['id'], ['keep_media_ids' => []])->assertOk()->assertJsonCount(0, 'data.media');
        $this->get($post['media'][0]['url'])->assertNotFound();
        $count = DB::table('posts')->count();
        $this->asUser($this->b)->postJson('/api/posts', ['body' => 'Try reuse', 'media_ids' => [$media]])->assertForbidden();
        $this->assertSame($count, DB::table('posts')->count());
    }

    public function test_unread_badge_counts_and_read_all_are_scoped_to_current_user(): void
    {
        $post = $this->asUser($this->a)->postJson('/api/posts', ['body' => 'Notification'])->json('data.id');
        $this->asUser($this->b)->putJson('/api/posts/'.$post.'/reaction', ['reaction' => 'like'])->assertOk();
        $this->asUser($this->a)->getJson('/api/me/social-counts')->assertOk()->assertJsonPath('data.notifications', 1);
        $this->putJson('/api/notifications/read')->assertOk();
        $this->getJson('/api/me/social-counts')->assertOk()->assertJsonPath('data.notifications', 0);
        $this->asUser($this->b)->getJson('/api/me/social-counts')->assertOk()->assertJsonPath('data.notifications', 0);
    }

    public function test_member_adapter_does_not_confuse_user_and_member_identifiers(): void
    {
        $member = Member::create(['id' => 90, 'name' => 'Different member ID', 'email' => 'different@example.test', 'join_date' => '2026-10-31']);
        $user = User::create(['name' => $member->name, 'email' => $member->email, 'password' => 'test-password', 'member_id' => 90, 'role' => 'user']);
        MemberEquipment::create(['member_id' => 90, 'name' => 'Correct owner equipment', 'category' => 'racket']);
        $this->asUser($this->b)->getJson('/api/member-profiles/90')->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonPath('data.member_id', 90);
        $this->getJson('/api/member-profiles/90/equipment')->assertOk()->assertJsonPath('data.data.0.name', 'Correct owner equipment');
    }

    public function test_legacy_generated_avatar_does_not_expose_the_private_email(): void
    {
        $this->a->member->update(['avatar' => 'https://i.pravatar.cc/150?u='.urlencode($this->a->email)]);
        $avatar = $this->asUser($this->b)->getJson('/api/profiles/'.$this->a->id)->assertOk()->json('data.avatar');
        $this->assertStringNotContainsString($this->a->email, $avatar);
        $this->assertStringNotContainsString(urlencode($this->a->email), $avatar);
    }
}
