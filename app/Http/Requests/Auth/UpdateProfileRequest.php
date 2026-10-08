<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user();
        $email = [
            'required',
            'email',
            'max:255',
            Rule::unique('users', 'email')->ignore($user?->id),
        ];

        if ($user?->member_id) {
            $email[] = Rule::unique('members', 'email')->ignore($user->member_id);
        }

        return [
            'name' => 'required|string|max:255',
            'email' => $email,
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|string|max:2048',
            'avatar_file' => 'nullable|file|image|max:5120|mimes:jpg,jpeg,png,webp,gif',
            'current_password' => 'required_with:password|string',
            'password' => 'nullable|string|min:8|confirmed',
        ];
    }

    protected function customMessages(): array
    {
        return [
            'avatar_file.image' => __('messages.upload_mime_invalid'),
            'avatar_file.mimes' => __('messages.upload_mime_invalid'),
            'avatar_file.max' => __('messages.upload_too_large'),
        ];
    }
}
