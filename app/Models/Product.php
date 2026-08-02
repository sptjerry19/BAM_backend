<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];
}
