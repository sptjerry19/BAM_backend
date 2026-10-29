<?php

namespace App\Models\CapitalRivals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardProperty extends Model
{
    protected $table = 'cr_properties';

    protected $fillable = [
        'room_id',
        'tile_index',
        'owner_seat',
        'level',
    ];

    protected $casts = [
        'tile_index' => 'integer',
        'owner_seat' => 'integer',
        'level' => 'integer',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
