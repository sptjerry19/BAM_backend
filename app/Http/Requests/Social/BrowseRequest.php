<?php

namespace App\Http\Requests\Social;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class BrowseRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|between:1,50',
            'category' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:100',
            'before' => 'nullable|integer|min:1',
            'after' => 'nullable|integer|min:0',
            'cursor' => 'nullable|string|max:500',
            'type' => 'nullable|in:image,video',
            'archive' => 'sometimes|boolean',
        ];
    }
}
