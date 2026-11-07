<?php

namespace App\Models\ContentStudio;

use App\Enums\ContentStudio\RightsStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContentSource extends Model
{
    // `rights_status` is not fillable: it changes only through an explicit,
    // audited action (admin API / RightsGate), never by mass assignment.
    protected $fillable = [
        'platform', 'bucket', 'external_id', 'canonical_url', 'url_hash', 'creator',
        'published_at', 'observed_at', 'downloaded_at', 'media_type', 'download_status',
        'download_basis', 'license_type', 'license_evidence_json', 'trend_evidence_json',
        'original_caption', 'proposed_caption', 'caption_status',
    ];

    protected $casts = [
        'rights_status' => RightsStatus::class,
        'published_at' => 'datetime',
        'observed_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'license_evidence_json' => 'array',
        'trend_evidence_json' => 'array',
    ];

    protected $attributes = [
        'rights_status' => 'rights_unverified',
    ];

    public static function hashUrl(string $url): string
    {
        return hash('sha256', trim($url));
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(ContentItem::class, 'content_item_sources', 'source_id', 'content_item_id')
            ->withPivot('usage')->withTimestamps();
    }
}
