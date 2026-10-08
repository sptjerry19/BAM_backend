<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Court extends Model
{
    protected $fillable = [
        'id',
        'name',
        'address',
        'district',
        'price',
        'surfaces',
        'courts',
        'status',
        'rating',
        'image',
        'x',
        'y',
        'lat',
        'lng',
        'phone',
        'hours',
    ];

    protected $casts = [
        'price' => 'integer',
        'courts' => 'integer',
        'rating' => 'float',
        'x' => 'float',
        'y' => 'float',
        'lat' => 'float',
        'lng' => 'float',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
