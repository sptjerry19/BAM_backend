<?php

namespace App\Models\ContentStudio;

use App\Enums\ContentStudio\RunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ContentPipelineRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'content_item_id', 'pipeline', 'status', 'resume_job_json', 'trace_id', 'started_at', 'finished_at', 'last_error',
    ];

    protected $casts = [
        'status' => RunStatus::class,
        'resume_job_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public static function start(?ContentItem $item, string $pipeline): self
    {
        return static::create([
            'content_item_id' => $item?->id,
            'pipeline' => $pipeline,
            'status' => RunStatus::Pending,
            'trace_id' => (string) Str::uuid(),
        ]);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ContentPipelineStep::class, 'run_id');
    }

    public function markFinished(RunStatus $status, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'last_error' => $error,
            'resume_job_json' => null, // nothing left to resume
        ])->save();
    }
}
