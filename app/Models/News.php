<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class News extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'tag',
        'title',
        'slug',
        'excerpt',
        'date',
        'read',
        'featured',
        'cover_caption',
        'body',
        'content',
        'image',
    ];

    protected $casts = [
        'featured' => 'boolean',
        'read' => 'integer',
        'date' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
