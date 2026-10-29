<?php

namespace App\Models\CapitalRivals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $table = 'cr_rooms';

    protected $fillable = [
        'code',
        'host_player_id',
        'status',
        'phase',
        'current_seat',
        'turn_deadline',
        'version',
        'settings',
        'state',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'state' => 'array',
        'turn_deadline' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'version' => 'integer',
        'current_seat' => 'integer',
        'host_player_id' => 'integer',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class, 'room_id')->orderBy('seat');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(BoardProperty::class, 'room_id')->orderBy('tile_index');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'room_id')->latest('id');
    }
}
