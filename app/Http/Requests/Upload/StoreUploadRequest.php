<?php

namespace App\Http\Requests\Upload;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class StoreUploadRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'folder' => 'nullable|string|max:100',
            'files' => 'required|array|min:1|max:12',
            'files.*' => 'required|file|image|max:5120|mimes:jpg,jpeg,png,webp,gif',
        ];
    }

    protected function customMessages(): array
    {
        return [
            'files.required' => __('messages.upload_invalid'),
            'files.*.image' => __('messages.upload_mime_invalid'),
            'files.*.mimes' => __('messages.upload_mime_invalid'),
            'files.*.max' => __('messages.upload_too_large'),
        ];
    }
}
