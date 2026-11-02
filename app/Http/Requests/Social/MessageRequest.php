<?php

namespace App\Http\Requests\Social;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class MessageRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => 'nullable|string|max:5000',
            'client_message_id' => 'required|uuid',
            'media_ids' => 'sometimes|array|max:4',
            'media_ids.*' => 'required|uuid',
        ];
    }
}
