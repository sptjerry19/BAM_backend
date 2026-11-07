<?php

namespace App\Models\ContentStudio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentPlan extends Model
{
    protected $fillable = ['channel_id', 'plan_date', 'status', 'strategy_version', 'generated_at'];

    protected $casts = [
        'plan_date' => 'date',
        'generated_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ContentChannel::class, 'channel_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'plan_id');
    }
}
