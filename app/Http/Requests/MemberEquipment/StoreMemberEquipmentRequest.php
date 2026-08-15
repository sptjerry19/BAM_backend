<?php

namespace App\Http\Requests\MemberEquipment;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class StoreMemberEquipmentRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'brand' => 'nullable|string|max:255',
            'category' => 'required|in:racket,shoes,shirt,shorts,bag,grip,other,shuttlecock,string',
            'price' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'image' => 'nullable|string',
        ];
    }
}
