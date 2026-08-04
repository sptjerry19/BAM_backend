<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'brand',
        'category',
        'price',
        'original_price',
        'image',
        'images',
        'stock',
        'rating',
        'reviews',
        'description',
        'featured',
    ];

    protected $casts = [
        'price' => 'integer',
        'original_price' => 'integer',
        'stock' => 'integer',
        'rating' => 'float',
        'reviews' => 'integer',
        'featured' => 'boolean',
        'images' => 'array',
    ];

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /** @alias productReviews */
    public function reviews(): HasMany
    {
        return $this->productReviews();
    }

    public function getAverageRatingAttribute(): ?float
    {
        $avg = $this->productReviews()->avg('rating');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    public function syncReviewStats(): void
    {
        $stats = $this->productReviews()
            ->selectRaw('COUNT(*) as total, AVG(rating) as avg_rating')
            ->first();

        $this->update([
            'reviews' => (int) ($stats->total ?? 0),
            'rating' => round((float) ($stats->avg_rating ?? 0), 1),
        ]);
    }
}
