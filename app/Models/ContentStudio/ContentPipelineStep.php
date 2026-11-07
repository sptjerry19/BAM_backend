<?php

namespace App\Models\ContentStudio;

use App\Enums\ContentStudio\StepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPipelineStep extends Model
{
    protected $fillable = [
        'run_id', 'step_name', 'status', 'attempt', 'started_at', 'finished_at',
        'heartbeat_at', 'lease_until', 'input_json', 'output_json', 'error_code', 'error_message',
    ];

    protected $casts = [
        'status' => StepStatus::class,
        'attempt' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'lease_until' => 'datetime',
        'input_json' => 'array',
        'output_json' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentPipelineRun::class, 'run_id');
    }

    /** Extend the lease while a long step is still making progress. */
    public function heartbeat(?int $seconds = null): void
    {
        $seconds ??= (int) config('content-studio.steps.lease_seconds');
        $this->forceFill(['heartbeat_at' => now(), 'lease_until' => now()->addSeconds($seconds)])->save();
    }
}
