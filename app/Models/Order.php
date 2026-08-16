<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = [
        'member_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'shipping_address',
        'note',
        'payment_method',
        'total',
        'status',
        'items',
    ];

    protected $casts = [
        'total' => 'integer',
        'items' => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
