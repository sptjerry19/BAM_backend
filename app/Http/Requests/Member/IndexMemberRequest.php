<?php

namespace App\Http\Requests\Member;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class IndexMemberRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'level' => 'nullable|in:beginner,intermediate,advanced,pro',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
