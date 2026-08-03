<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|unique:members,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:30',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('auth.validation.name_required'),
            'name.string' => __('auth.validation.name_string'),
            'name.max' => __('auth.validation.name_max'),
            'email.required' => __('auth.validation.email_required'),
            'email.email' => __('auth.validation.email_email'),
            'email.unique' => __('auth.validation.email_unique'),
            'password.required' => __('auth.validation.password_required'),
            'password.string' => __('auth.validation.password_string'),
            'password.min' => __('auth.validation.password_min'),
            'password.confirmed' => __('auth.validation.password_confirmed'),
        ];
    }
}
