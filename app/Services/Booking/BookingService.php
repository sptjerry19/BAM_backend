<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Court;

class BookingService
{
    public function create(array $data): Booking
    {
        $court = Court::findOrFail($data['court_id']);

        $data['total_price'] = (int) ($court->price * $data['hours']);
        $data['status'] = $data['status'] ?? 'pending';

        return Booking::create($data)->load(['court', 'member']);
    }
}
