<?php

namespace App\Http\Requests\CapitalRivals;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class CreateRoomRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:32',
            'entry_fee' => 'sometimes|integer|min:0|max:1000000',
            'winnings_target' => 'sometimes|integer|min:1500000|max:100000000',
        ];
    }
}
