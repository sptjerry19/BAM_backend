<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|string|unique:products,id',
            'name' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'category' => 'required|in:racket,shoes,shirt,shorts,shuttlecock,bag,grip,string',
            'price' => 'required|integer|min:0',
            'original_price' => 'nullable|integer|min:0',
            'image' => 'nullable|string|max:2048',
            'images' => 'nullable|array|max:12',
            'images.*' => 'nullable|string|max:2048',
            'image_files' => 'nullable|array|max:12',
            'image_files.*' => 'nullable|file|image|max:5120|mimes:jpg,jpeg,png,webp,gif',
            'stock' => 'required|integer|min:0',
            'rating' => 'nullable|numeric|min:0|max:5',
            'reviews' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'featured' => 'nullable|boolean',
        ];
    }

    protected function customMessages(): array
    {
        return [
            'image_files.*.image' => __('messages.upload_mime_invalid'),
            'image_files.*.mimes' => __('messages.upload_mime_invalid'),
            'image_files.*.max' => __('messages.upload_too_large'),
        ];
    }
}
