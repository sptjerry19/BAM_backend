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
            'action' => 'required|string|in:confirm_roll,reroll,escape_island,wait_island,travel,festival,takeover,decline_takeover,accept_winnings,island_pay,island_roll,use_escape_card,championship,chance_target,use_shield,liquidate,discard_card,surrender',
            'tile_index' => 'nullable|integer|min:0|max:31',
        ];
    }
}
