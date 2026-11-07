<?php

namespace App\Models\ContentStudio;

use App\Enums\ContentStudio\ContentSlot;
use App\Enums\ContentStudio\ContentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentItem extends Model
{
    // `status` and `lock_version` are intentionally NOT fillable: they change
    // only through ContentStateMachine so every transition is validated and logged.
    protected $fillable = [
        'plan_id', 'channel_id', 'slot', 'content_type', 'title', 'hook', 'caption',
        'hashtags_json', 'script_json', 'brief_json', 'fingerprint', 'replaces_item_id', 'scheduled_at',
    ];

    protected $casts = [
        'status' => ContentStatus::class,
        'slot' => ContentSlot::class,
        'hashtags_json' => 'array',
        'script_json' => 'array',
        'brief_json' => 'array',
        'lock_version' => 'integer',
        'scheduled_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'idea',
        'lock_version' => 0,
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class, 'plan_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ContentChannel::class, 'channel_id');
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(ContentSource::class, 'content_item_sources', 'content_item_id', 'source_id')
            ->withPivot('usage')->withTimestamps();
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ContentAsset::class, 'content_item_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ContentPipelineRun::class, 'content_item_id');
    }
}
