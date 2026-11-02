<?php

namespace App\Http\Requests\Social;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_type' => 'required|in:user,post,comment',
            'subject_id' => 'required|integer|min:1',
            'reason' => 'required|string|max:2000',
        ];
    }
}
