<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = [
        'court_id',
        'court_number',
        'member_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'booking_date',
        'start_time',
        'hours',
        'total_price',
        'status',
        'payment_method',
        'note',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'hours' => 'float',
        'total_price' => 'integer',
        'court_number' => 'integer',
    ];

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
