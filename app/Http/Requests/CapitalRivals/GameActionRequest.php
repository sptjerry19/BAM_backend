<?php

namespace App\Http\Requests\CapitalRivals;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class GameActionRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => 'required|string|in:confirm_roll,reroll,escape_island,wait_island,travel,festival,takeover,decline_takeover,accept_winnings',
            'tile_index' => 'required_if:action,travel,festival|integer|min:0|max:39',
        ];
    }
}
