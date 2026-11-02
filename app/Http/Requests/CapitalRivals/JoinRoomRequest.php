<?php

namespace App\Http\Requests\CapitalRivals;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class JoinRoomRequest extends FormRequest
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
            'player_token' => 'nullable|string|max:64',
        ];
    }
}
