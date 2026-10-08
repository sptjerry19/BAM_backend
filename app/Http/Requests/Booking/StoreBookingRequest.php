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
            'court_id' => 'required|exists:courts,id',
            'member_id' => 'nullable|exists:members,id',
            'booking_date' => 'required|date',
            'slots' => 'required|array|min:1|max:40',
            'slots.*.court_number' => 'required|integer|min:1',
            'slots.*.start_time' => 'required|date_format:H:i',
            'slots.*.hours' => 'required|numeric|min:0.5|max:12',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:20',
            'customer_email' => 'required|email|max:255',
            'payment_method' => 'required|in:venue,bank,momo',
            'note' => 'nullable|string|max:1000',
        ];
    }

    protected function customAttributes(): array
    {
        return [
            'slots' => __('messages.attributes.slots'),
            'slots.*.court_number' => __('messages.attributes.court_number'),
            'slots.*.start_time' => __('messages.attributes.start_time'),
            'slots.*.hours' => __('messages.attributes.booking_hours'),
        ];
    }
}
