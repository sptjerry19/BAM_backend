<?php

namespace App\Http\Requests\News;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class StoreNewsRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('featured')) {
            $this->merge([
                'featured' => filter_var($this->input('featured'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'category_id' => 'required|integer|exists:categories,id',
            'title' => 'required|string|max:255',
            'tag' => 'nullable|string|max:100',
            'excerpt' => 'nullable|string|max:1000',
            'date' => 'nullable|date',
            'read' => 'nullable|integer|min:0|max:120',
            'featured' => 'nullable|boolean',
            'cover_caption' => 'nullable|string|max:255',
            'content' => 'required|string',
            'body' => 'nullable|string',
            'image' => 'nullable|string|max:2048',
            'slug' => 'nullable|string|max:255|unique:news,slug',
        ];
    }
}
