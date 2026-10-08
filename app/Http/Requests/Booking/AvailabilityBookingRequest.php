<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class AvailabilityBookingRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date',
        ];
    }

    protected function customAttributes(): array
    {
        return [
            'date' => __('messages.attributes.booking_date'),
        ];
    }
}
