<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\Concerns\HasApiValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    use HasApiValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => 'nullable|string|exists:members,id',
            'customer_name' => 'required|string|max:120',
            'customer_phone' => 'required|string|max:32',
            'customer_email' => 'required|email|max:255',
            'shipping_address' => 'required|string|max:500',
            'note' => 'nullable|string|max:1000',
            'payment_method' => 'required|in:cod,bank,apple_pay,momo',
            'status' => 'nullable|in:pending,paid,shipped',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|string|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'nullable|integer|min:0',
        ];
    }

    protected function customAttributes(): array
    {
        return [
            'customer_name' => __('messages.attributes.customer_name'),
            'customer_phone' => __('messages.attributes.customer_phone'),
            'customer_email' => __('messages.attributes.customer_email'),
            'shipping_address' => __('messages.attributes.shipping_address'),
            'payment_method' => __('messages.attributes.payment_method'),
        ];
    }
}
