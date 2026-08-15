<?php

namespace App\Http\Requests\Court;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCourtRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string|max:255',
            'district' => 'sometimes|string|max:255',
            'price' => 'sometimes|integer|min:0',
            'surfaces' => 'sometimes|string|max:255',
            'courts' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:open,busy,closed',
            'rating' => 'nullable|numeric|min:0|max:5',
            'image' => 'nullable|string|max:255',
            'x' => 'nullable|numeric',
            'y' => 'nullable|numeric',
            'phone' => 'nullable|string|max:50',
            'hours' => 'nullable|string|max:100',
        ];
    }
}
