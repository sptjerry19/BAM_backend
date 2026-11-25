<?php

namespace App\Models\ContentStudio;

use Illuminate\Database\Eloquent\Model;

class RacketSpec extends Model
{
    protected $table = 'racket_specs';

    protected $fillable = ['brand', 'model_name', 'source_url', 'specs_json', 'content_hash', 'fetched_at'];

    protected $casts = ['specs_json' => 'array', 'fetched_at' => 'datetime'];
}
