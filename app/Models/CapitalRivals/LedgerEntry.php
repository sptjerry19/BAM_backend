<?php

namespace App\Models\CapitalRivals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    protected $table = 'cr_transactions';

    protected $fillable = [
        'room_id',
        'type',
        'from_player_id',
        'to_player_id',
        'amount',
        'tile_index',
        'idempotency_key',
    ];

    protected $casts = [
        'from_player_id' => 'integer',
        'to_player_id' => 'integer',
        'amount' => 'integer',
        'tile_index' => 'integer',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
