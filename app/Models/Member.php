<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
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

    public function getAvatarAttribute(?string $value): ?string
    {
        // Older registrations included a private email in the public avatar URL.
        $url = $value ? parse_url($value) : false;
        if ($url && ($url['host'] ?? '') === 'i.pravatar.cc') {
            parse_str($url['query'] ?? '', $query);
            if (filter_var($query['u'] ?? '', FILTER_VALIDATE_EMAIL)) {
                return 'https://i.pravatar.cc/150?u='.hash('sha256', 'member:'.$this->id.':'.config('app.key'));
            }
        }

        return $value;
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
