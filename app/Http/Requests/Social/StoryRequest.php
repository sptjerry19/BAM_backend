<?php

namespace App\Http\Requests\Social;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class StoryRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => 'nullable|string|max:500',
            'audience' => 'required|in:public,friends,only_me',
            'media_ids' => 'required|array|min:1|max:1',
            'media_ids.*' => 'required|uuid',
        ];
    }
}
