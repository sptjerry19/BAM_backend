<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;

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
        'sold_count',
    ];

    protected $casts = [
        'price' => 'integer',
        'original_price' => 'integer',
        'stock' => 'integer',
        'rating' => 'float',
        'reviews' => 'integer',
        'featured' => 'boolean',
        'sold_count' => 'integer',
        'images' => 'array',
    ];

    public function searchableAs(): string
    {
        return 'products';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => (string) $this->name,
            'brand' => (string) $this->brand,
            'category' => (string) $this->category,
            'description' => (string) ($this->description ?? ''),
            'price' => (int) $this->price,
            'sold_count' => (int) ($this->sold_count ?? 0),
            'featured' => (bool) $this->featured,
            'rating' => (float) $this->rating,
            'stock' => (int) $this->stock,
        ];
    }

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
