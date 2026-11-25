<?php

namespace App\Models\ContentStudio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentItemProduct extends Model
{
    protected $fillable = ['content_item_id', 'product_id', 'role', 'facts_snapshot_json', 'price_snapshot_json'];

    protected $casts = [
        'facts_snapshot_json' => 'array',
        'price_snapshot_json' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }
}
