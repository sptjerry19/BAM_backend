<?php

namespace App\Http\Requests\Court;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class StoreCourtRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|string|unique:courts,id',
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'district' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'surfaces' => 'required|string|max:255',
            'courts' => 'required|integer|min:1',
            'status' => 'required|in:open,busy,closed',
            'rating' => 'nullable|numeric|min:0|max:5',
            'image' => 'nullable|string|max:255',
            'x' => 'nullable|numeric',
            'y' => 'nullable|numeric',
            'phone' => 'nullable|string|max:50',
            'hours' => 'nullable|string|max:100',
        ];
    }
}
