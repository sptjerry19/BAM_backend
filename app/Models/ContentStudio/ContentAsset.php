<?php

namespace App\Models\ContentStudio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentAsset extends Model
{
    protected $fillable = [
        'content_item_id', 'source_id', 'kind', 'storage_disk', 'path', 'checksum_sha256',
        'mime', 'bytes', 'width', 'height', 'duration_ms', 'license_state',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ContentSource::class, 'source_id');
    }
}
