<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'court_id' => 'required|string|exists:courts,id',
            'member_id' => 'nullable|string|exists:members,id',
            'booking_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'hours' => 'required|numeric|min:0.5|max:12',
            'status' => 'nullable|in:pending,confirmed,cancelled',
        ]);

        $court = Court::findOrFail($validated['court_id']);
        $validated['total_price'] = (int) ($court->price * $validated['hours']);
        $validated['status'] = $validated['status'] ?? 'pending';

        $booking = Booking::create($validated);

        return response()->json($booking->load(['court', 'member']), 201);
    }
}
