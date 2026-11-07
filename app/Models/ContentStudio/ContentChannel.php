<?php

namespace App\Models\ContentStudio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentChannel extends Model
{
    protected $fillable = ['platform', 'handle', 'timezone', 'provider', 'settings_json', 'automation_enabled'];

    protected $casts = [
        'settings_json' => 'array',
        'automation_enabled' => 'boolean',
    ];

    /** The channel configured in config/content-studio.php (created on first use). */
    public static function default(): self
    {
        return static::firstOrCreate(
            [
                'platform' => config('content-studio.channel.platform'),
                'handle' => config('content-studio.channel.handle'),
            ],
            ['timezone' => config('content-studio.timezone')],
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'channel_id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(ContentSetting::class, 'channel_id');
    }
}
