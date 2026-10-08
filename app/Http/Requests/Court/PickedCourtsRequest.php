<?php

namespace App\Http\Requests\Court;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class PickedCourtsRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'integer|min:1',
        ];
    }
}
