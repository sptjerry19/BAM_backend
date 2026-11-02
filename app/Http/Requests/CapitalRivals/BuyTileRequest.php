<?php

namespace App\Http\Requests\CapitalRivals;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class BuyTileRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['level' => ['sometimes', 'integer', 'in:0,1,2,3']];
    }
}
