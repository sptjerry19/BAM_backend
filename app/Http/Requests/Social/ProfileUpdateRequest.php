<?php

namespace App\Http\Requests\Social;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'username' => ['nullable', 'string', 'alpha_dash', 'max:50', Rule::unique('user_profiles', 'username')->ignore($this->user()->id, 'user_id')],
            'bio' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'work' => 'nullable|string|max:255',
            'education' => 'nullable|string|max:255',
            'website' => 'nullable|url:http,https|max:255',
            'interests' => 'nullable|string|max:255',
            'handed' => 'nullable|in:left,right,both',
            'playing_style' => 'nullable|string|max:255',
            'club' => 'nullable|string|max:255',
            'cover_position' => 'sometimes|integer|between:0,100',
        ];
    }
}
