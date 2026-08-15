<?php

namespace App\Http\Requests\ProductReview;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductReviewRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string',
            'member_id' => 'nullable|string|exists:members,id',
            'user_name' => 'nullable|string|max:255',
            'avatar' => 'nullable|string|max:2048',
        ];
    }
}
