<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Studio foundation. Only creates new `content_*` tables; no existing
 * table (bookings, members, products, news, social) is altered.
 *
 * Deferred to later phases: content_publications, content_metric_snapshots,
 * content_channel_metrics (Phase 4), content_item_products (Phase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_channels', function (Blueprint $t) {
            $t->id();
            $t->string('platform', 20);
            $t->string('handle', 100);
            $t->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $t->string('provider', 40)->nullable();
            $t->json('settings_json')->nullable();
            $t->boolean('automation_enabled')->default(false);
            $t->timestamps();
            $t->unique(['platform', 'handle']);
        });

        Schema::create('content_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained('content_channels');
            $t->date('plan_date');
            $t->string('status', 25)->default('draft');
            $t->string('strategy_version', 64)->nullable();
            $t->timestamp('generated_at')->nullable();
            $t->timestamps();
            $t->unique(['channel_id', 'plan_date']);
        });

        Schema::create('content_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->nullable()->constrained('content_plans')->nullOnDelete();
            $t->foreignId('channel_id')->constrained('content_channels');
            $t->string('slot', 20);
            $t->string('content_type', 20);
            $t->string('title')->nullable();
            $t->text('hook')->nullable();
            $t->text('caption')->nullable();
            $t->json('hashtags_json')->nullable();
            $t->json('script_json')->nullable();
            $t->json('brief_json')->nullable();
            $t->string('fingerprint', 64)->nullable();
            $t->string('status', 25)->default('idea');
            $t->string('blocked_reason')->nullable();
            $t->unsignedBigInteger('replaces_item_id')->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamps();
            // NULL fingerprints do not collide in MySQL/SQLite unique indexes.
            $t->unique(['channel_id', 'fingerprint']);
            $t->index(['channel_id', 'status']);
            $t->index('plan_id');
            $t->foreign('replaces_item_id')->references('id')->on('content_items')->nullOnDelete();
        });

        Schema::create('content_sources', function (Blueprint $t) {
            $t->id();
            $t->string('platform', 20);
            $t->string('bucket', 20)->default('news');
            $t->string('external_id', 191)->nullable();
            $t->text('canonical_url');
            $t->char('url_hash', 64)->unique();
            $t->string('creator', 191)->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('observed_at')->nullable();
            $t->timestamp('downloaded_at')->nullable();
            $t->string('media_type', 20)->nullable();
            $t->string('download_status', 30)->nullable();
            $t->string('download_basis', 100)->nullable();
            // Fail closed: nothing is publishable until evidence says so.
            $t->string('rights_status', 25)->default('rights_unverified');
            $t->string('license_type', 100)->nullable();
            $t->json('license_evidence_json')->nullable();
            $t->json('trend_evidence_json')->nullable();
            $t->text('original_caption')->nullable();
            $t->text('proposed_caption')->nullable();
            $t->string('caption_status', 20)->nullable();
            $t->timestamps();
            $t->index(['platform', 'rights_status']);
            $t->index(['bucket', 'rights_status']);
        });

        Schema::create('content_item_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $t->foreignId('source_id')->constrained('content_sources')->cascadeOnDelete();
            $t->string('usage', 20)->default('reference');
            $t->timestamps();
            $t->unique(['content_item_id', 'source_id']);
        });

        Schema::create('content_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_item_id')->nullable()->constrained('content_items')->cascadeOnDelete();
            $t->foreignId('source_id')->nullable()->constrained('content_sources')->nullOnDelete();
            $t->string('kind', 30);
            $t->string('storage_disk', 30);
            $t->string('path', 500);
            $t->char('checksum_sha256', 64)->nullable();
            $t->string('mime', 100)->nullable();
            $t->unsignedBigInteger('bytes')->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('license_state', 25)->nullable();
            $t->timestamps();
            $t->index(['kind', 'checksum_sha256']);
        });

        Schema::create('content_pipeline_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // Nullable: system runs (smoke test, maintenance) have no content item.
            $t->foreignId('content_item_id')->nullable()->constrained('content_items')->cascadeOnDelete();
            $t->string('pipeline', 40);
            $t->string('status', 20)->default('pending');
            // {class, args}: the job that should run next. Lets content:recover
            // re-dispatch after a lost queue message, a restart or a pause.
            $t->json('resume_job_json')->nullable();
            $t->char('trace_id', 36);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->index(['status', 'started_at']);
            $t->index('content_item_id');
        });

        Schema::create('content_pipeline_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('run_id')->constrained('content_pipeline_runs')->cascadeOnDelete();
            $t->string('step_name', 60);
            $t->string('status', 20);
            $t->unsignedSmallInteger('attempt');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('heartbeat_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->json('input_json')->nullable();
            $t->json('output_json')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->text('error_message')->nullable();
            $t->timestamps();
            $t->unique(['run_id', 'step_name', 'attempt']);
            $t->index(['status', 'lease_until']);
        });

        Schema::create('content_ai_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_item_id')->nullable()->constrained('content_items')->nullOnDelete();
            $t->string('purpose', 40);
            $t->string('model', 80);
            $t->string('prompt_version', 64)->nullable();
            $t->string('response_id', 100)->nullable();
            $t->unsignedInteger('tokens_in')->default(0);
            $t->unsignedInteger('tokens_out')->default(0);
            $t->decimal('estimated_cost_usd', 10, 6)->default(0);
            $t->string('status', 20);
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['created_at', 'model']);
        });

        Schema::create('content_events', function (Blueprint $t) {
            $t->id();
            $t->char('trace_id', 36)->nullable();
            $t->string('actor', 80);
            $t->string('action', 80);
            $t->string('entity_type', 60)->nullable();
            $t->string('entity_id', 64)->nullable();
            $t->json('payload_redacted')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['trace_id', 'created_at']);
            $t->index(['entity_type', 'entity_id']);
        });

        Schema::create('content_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained('content_channels')->cascadeOnDelete();
            $t->string('key', 80);
            $t->json('value_json');
            $t->timestamps();
            $t->unique(['channel_id', 'key']);
        });
    }

    public function down(): void
    {
        foreach ([
            'content_settings', 'content_events', 'content_ai_runs', 'content_pipeline_steps',
            'content_pipeline_runs', 'content_assets', 'content_item_sources', 'content_sources',
            'content_items', 'content_plans', 'content_channels',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
