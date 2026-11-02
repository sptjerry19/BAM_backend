<?php

namespace App\Models\CapitalRivals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Player extends Model
{
    protected $table = 'cr_players';

    protected $hidden = [
        'player_token',
    ];

    protected $fillable = [
        'room_id',
        'seat',
        'turn_order',
        'name',
        'user_id',
        'player_token',
        'skin',
        'cash',
        'position',
        'ready',
        'bankrupt',
    ];

    protected $casts = [
        'seat' => 'integer',
        'turn_order' => 'integer',
        'user_id' => 'integer',
        'cash' => 'integer',
        'position' => 'integer',
        'ready' => 'boolean',
        'bankrupt' => 'boolean',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
