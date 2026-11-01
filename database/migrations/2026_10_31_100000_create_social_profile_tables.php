<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('username')->nullable()->unique();
            $t->text('bio')->nullable();
            foreach (['city', 'work', 'education', 'website', 'interests', 'handed', 'playing_style', 'club'] as $field) {
                $t->string($field)->nullable();
            }
            $t->unsignedTinyInteger('cover_position')->default(50);
            $t->timestamps();
        });
        Schema::create('profile_privacy_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('field', 40);
            $t->string('audience', 20)->default('only_me');
            $t->unique(['user_id', 'field']);
        });
        Schema::create('friendships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_low')->constrained('users')->cascadeOnDelete();
            $t->foreignId('user_high')->constrained('users')->cascadeOnDelete();
            $t->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $t->string('status', 20)->default('pending');
            $t->timestamps();
            $t->unique(['user_low', 'user_high']);
        });
        foreach (['user_follows', 'user_blocks'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('target_id')->constrained('users')->cascadeOnDelete();
                $t->timestamp('created_at');
                $t->unique(['user_id', 'target_id']);
            });
        }
        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('body')->nullable();
            $t->unsignedBigInteger('court_id')->nullable()->index();
            $t->string('audience', 20)->default('public');
            $t->boolean('pinned')->default(false);
            $t->boolean('hidden_from_profile')->default(false);
            $t->foreignId('shared_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $t->timestamp('archived_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('post_reactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('reaction', 16);
            $t->unique(['post_id', 'user_id']);
        });
        Schema::create('saved_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unique(['post_id', 'user_id']);
        });
        Schema::create('social_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $t->string('type', 30);
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'id']);
            $t->unique(['user_id', 'actor_id', 'type', 'subject_id'], 'social_notice_actor_subject_unique');
        });
        Schema::create('reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('subject_type', 20);
            $t->unsignedBigInteger('subject_id');
            $t->text('reason');
            $t->string('status', 20)->default('pending');
            $t->timestamps();
        });
        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_low')->constrained('users')->cascadeOnDelete();
            $t->foreignId('user_high')->constrained('users')->cascadeOnDelete();
            $t->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $t->string('status', 20)->default('pending');
            $t->timestamps();
            $t->unique(['user_low', 'user_high']);
        });
        Schema::create('conversation_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('last_read_id')->default(0);
            $t->unique(['conversation_id', 'user_id']);
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('client_message_id');
            $t->text('body')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'client_message_id']);
            $t->index(['conversation_id', 'id']);
        });
        Schema::create('social_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('event', 40);
            $t->json('payload');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->index(['published_at', 'id']);
        });
        Schema::create('albums', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('audience', 20)->default('public');
            $t->timestamps();
        });
        Schema::create('stories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('audience', 20)->default('public');
            $t->text('body')->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
        });
        Schema::create('story_views', function (Blueprint $t) {
            $t->id();
            $t->foreignId('story_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('reaction', 16)->nullable();
            $t->timestamp('created_at');
            $t->unique(['story_id', 'user_id']);
        });
        Schema::create('story_highlights', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('audience', 20)->default('public');
            $t->timestamps();
        });
        Schema::create('story_highlight_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('highlight_id')->constrained('story_highlights')->cascadeOnDelete();
            $t->foreignId('story_id')->constrained()->cascadeOnDelete();
            $t->unique(['highlight_id', 'story_id']);
        });
        Schema::create('media_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('story_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('album_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('equipment_id')->nullable()->constrained('member_equipments')->cascadeOnDelete();
            $t->string('purpose', 20)->default('post');
            $t->string('disk', 30);
            $t->string('path');
            $t->string('mime', 80);
            $t->unsignedBigInteger('size');
            $t->string('status', 20)->default('ready');
            $t->string('poster_path')->nullable();
            $t->unsignedInteger('duration')->nullable();
            $t->string('failure_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('post_tags', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('pending');
            $t->unique(['post_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('photo_tags', function (Blueprint $t) {
            $t->id();
            $t->uuid('media_id');
            $t->foreign('media_id')->references('id')->on('media_assets')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('pending');
            $t->unique(['media_id', 'user_id']);
            $t->timestamps();
        });
        DB::table('users')->orderBy('id')->chunkById(200, function ($users) {
            DB::table('user_profiles')->insertOrIgnore($users->map(fn ($u) => ['user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()])->all());
        });
    }

    public function down(): void
    {
        foreach (['photo_tags', 'post_tags', 'media_assets', 'story_highlight_items', 'story_highlights', 'story_views', 'stories', 'albums', 'social_outbox', 'messages', 'conversation_participants', 'conversations', 'reports', 'social_notifications', 'saved_posts', 'post_reactions', 'comments', 'posts', 'user_blocks', 'user_follows', 'friendships', 'profile_privacy_settings', 'user_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
