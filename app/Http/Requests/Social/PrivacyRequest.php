<?php

namespace App\Http\Requests\Social;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class PrivacyRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'about' => 'sometimes|required|in:public,friends,only_me',
            'email' => 'sometimes|required|in:public,friends,only_me',
            'phone' => 'sometimes|required|in:public,friends,only_me',
            'equipment' => 'sometimes|required|in:public,friends,only_me',
            'friends' => 'sometimes|required|in:public,friends,only_me',
        ];
    }
}
