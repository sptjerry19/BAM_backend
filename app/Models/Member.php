<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'email',
        'phone',
        'avatar',
        'level',
        'points',
        'matches',
        'wins',
        'total_cost',
        'join_date',
        'status',
        'equipment',
    ];

    protected $casts = [
        'points' => 'integer',
        'matches' => 'integer',
        'wins' => 'integer',
        'total_cost' => 'integer',
        'join_date' => 'date',
        'equipment' => 'array',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function customEquipments(): HasMany
    {
        return $this->hasMany(MemberEquipment::class);
    }
}
