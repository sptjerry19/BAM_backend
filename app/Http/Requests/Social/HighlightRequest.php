<?php

namespace App\Http\Requests\Social;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class HighlightRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'audience' => 'required|in:public,friends,only_me',
            'story_ids' => 'required|array|min:1|max:30',
            'story_ids.*' => 'required|integer|exists:stories,id',
        ];
    }
}
