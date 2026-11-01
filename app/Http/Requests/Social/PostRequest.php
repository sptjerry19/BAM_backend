<?php

namespace App\Http\Requests\Social;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class PostRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => 'nullable|string|max:10000',
            'audience' => 'sometimes|required|in:public,friends,only_me',
            'media_ids' => 'sometimes|array|max:10',
            'media_ids.*' => 'required|uuid',
            'keep_media_ids' => 'sometimes|array|max:10',
            'keep_media_ids.*' => 'required|uuid',
            'court_id' => 'nullable|integer|exists:courts,id',
            'shared_post_id' => 'nullable|integer|exists:posts,id',
            'archived' => 'sometimes|boolean',
            'hidden' => 'sometimes|boolean',
        ];
    }
}
