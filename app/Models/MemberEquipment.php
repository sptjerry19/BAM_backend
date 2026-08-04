<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberEquipment extends Model
{
    public $incrementing = false;

    protected $table = 'member_equipments';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'member_id',
        'name',
        'brand',
        'category',
        'price',
        'notes',
        'image',
    ];

    protected $casts = [
        'price' => 'integer',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
