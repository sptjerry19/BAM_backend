<?php

namespace App\Models\ContentStudio;

use Illuminate\Database\Eloquent\Model;

class ContentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['trace_id', 'actor', 'action', 'entity_type', 'entity_id', 'payload_redacted'];

    protected $casts = ['payload_redacted' => 'array'];
}
