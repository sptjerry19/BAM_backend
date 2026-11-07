<?php

namespace App\Models\ContentStudio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentSetting extends Model
{
    protected $fillable = ['channel_id', 'key', 'value_json'];

    protected $casts = ['value_json' => 'array'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ContentChannel::class, 'channel_id');
    }
}
