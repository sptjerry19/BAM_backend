<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'court_id' => 'required|string|exists:courts,id',
            'member_id' => 'nullable|string|exists:members,id',
            'booking_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'hours' => 'required|numeric|min:0.5|max:12',
            'status' => 'nullable|in:pending,confirmed,cancelled',
        ];
    }
}
