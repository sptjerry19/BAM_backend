<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class IndexProductRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => 'nullable|string|in:racket,shoes,shirt,shorts,shuttlecock,bag,grip,string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
