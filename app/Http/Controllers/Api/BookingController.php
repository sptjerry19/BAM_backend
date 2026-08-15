<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;

class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings)
    {
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        return $this->success(
            $this->bookings->create($request->validated()),
            __('messages.created'),
            201
        );
    }
}
