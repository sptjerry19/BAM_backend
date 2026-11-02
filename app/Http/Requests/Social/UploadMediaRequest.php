<?php

namespace App\Http\Requests\Social;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => in_array($this->input('purpose'), ['post', 'story'])
                ? 'required|file|mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm|max:51200'
                : 'required|file|mimes:jpg,jpeg,png,webp,gif|max:5120',
            'purpose' => 'required|in:post,avatar,cover,message,story',
        ];
    }
}
