<?php

namespace App\Models\ContentStudio;

use Illuminate\Database\Eloquent\Model;

class ContentAiRun extends Model
{
    protected $fillable = [
        'content_item_id', 'purpose', 'model', 'prompt_version', 'response_id',
        'tokens_in', 'tokens_out', 'estimated_cost_usd', 'status', 'error',
    ];

    protected $casts = ['estimated_cost_usd' => 'decimal:6'];
}
