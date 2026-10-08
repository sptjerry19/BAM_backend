<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Court;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public const SLOT_MINUTES = 30;

    public const TZ = 'Asia/Ho_Chi_Minh';

    public function availability(Court $court, string $date): array
    {
        [$open, $close] = $this->parseOperatingHours($court->hours);

        $bookings = Booking::query()
            ->where('court_id', $court->id)
            ->whereDate('booking_date', $date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->get()
            ->map(fn (Booking $booking) => [
                'court_number' => (int) ($booking->court_number ?: 1),
                'start_time' => $this->formatTime($booking->start_time),
                'hours' => (float) $booking->hours,
                'status' => $booking->status,
            ])
            ->values();

        return [
            'date' => $date,
            'status' => $court->status,
            'price' => (int) $court->price,
            'court_count' => max(1, (int) $court->courts),
            'hours_label' => $court->hours,
            'open_minutes' => $open,
            'close_minutes' => $close,
            'slot_minutes' => self::SLOT_MINUTES,
            'grid_start_minutes' => 5 * 60,
            'grid_end_minutes' => 24 * 60,
            'min_hours' => $this->minHoursForDate($date),
            'bookings' => $bookings,
        ];
    }

    /**
     * @return array{bookings: array<int, Booking>, slots: array<int, array>, court_numbers: array<int, int>, total_price: int, booking_date: string, payment_method: string|null, court: Court}
     */
    public function create(array $data): array
    {
        $court = Court::findOrFail($data['court_id']);
        $slots = array_values($data['slots'] ?? []);

        if ($slots === []) {
            throw ValidationException::withMessages([
                'slots' => [__('messages.validation.required')],
            ]);
        }

        $status = ($data['payment_method'] ?? '') === 'venue' ? 'confirmed' : 'pending';
        $memberId = $data['member_id'] ?? auth()->user()?->member_id;
        if ($memberId && ! Member::query()->whereKey($memberId)->exists()) {
            $memberId = null;
        }

        $created = DB::transaction(function () use ($court, $data, $slots, $status, $memberId) {
            foreach ($slots as $index => $slot) {
                $this->assertBookable($court, array_merge($data, [
                    'court_number' => $slot['court_number'],
                    'start_time' => $slot['start_time'],
                    'hours' => $slot['hours'],
                ]), 'slots.'.$index);
            }

            $rows = [];
            foreach ($slots as $slot) {
                $hours = (float) $slot['hours'];
                $rows[] = Booking::create([
                    'court_id' => $court->id,
                    'court_number' => (int) $slot['court_number'],
                    'member_id' => $memberId ?: null,
                    'customer_name' => $data['customer_name'] ?? null,
                    'customer_phone' => $data['customer_phone'] ?? null,
                    'customer_email' => $data['customer_email'] ?? null,
                    'booking_date' => $data['booking_date'],
                    'start_time' => $slot['start_time'],
                    'hours' => $hours,
                    'total_price' => (int) round($court->price * $hours),
                    'status' => $status,
                    'payment_method' => $data['payment_method'] ?? null,
                    'note' => $data['note'] ?? null,
                ])->load(['court', 'member']);
            }

            return $rows;
        });

        $numbers = array_map(fn (Booking $b) => (int) $b->court_number, $created);

        return [
            'bookings' => $created,
            'slots' => array_map(fn (Booking $b) => [
                'court_number' => (int) $b->court_number,
                'start_time' => $this->formatTime($b->start_time),
                'hours' => (float) $b->hours,
                'total_price' => (int) $b->total_price,
            ], $created),
            'court_numbers' => $numbers,
            'total_price' => array_sum(array_map(fn (Booking $b) => (int) $b->total_price, $created)),
            'booking_date' => $data['booking_date'],
            'payment_method' => $data['payment_method'] ?? null,
            'court' => $court,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertBookable(Court $court, array $data, string $prefix = ''): void
    {
        $field = fn (string $name) => $prefix !== '' ? $prefix.'.'.$name : $name;

        if ($court->status === 'closed') {
            throw ValidationException::withMessages([
                'court_id' => [__('messages.court_closed')],
            ]);
        }

        $courtNumber = (int) $data['court_number'];
        if ($courtNumber < 1 || $courtNumber > max(1, (int) $court->courts)) {
            throw ValidationException::withMessages([
                $field('court_number') => [__('messages.validation.exists')],
            ]);
        }

        $hours = (float) $data['hours'];
        $minHours = $this->minHoursForDate($data['booking_date']);
        if ($hours + 0.001 < $minHours) {
            throw ValidationException::withMessages([
                $field('hours') => [__('messages.booking_too_short', ['hours' => $minHours])],
            ]);
        }

        [$open, $close] = $this->parseOperatingHours($court->hours);
        $start = $this->toMinutes($data['start_time']);
        $end = $start + (int) round($hours * 60);

        if ($start < $open || $end > $close) {
            throw ValidationException::withMessages([
                $field('start_time') => [__('messages.slot_locked')],
            ]);
        }

        $startAt = Carbon::parse($data['booking_date'].' '.$data['start_time'], self::TZ);
        if ($startAt->lt(now(self::TZ))) {
            throw ValidationException::withMessages([
                $field('start_time') => [__('messages.slot_locked')],
            ]);
        }

        if ($this->overlaps($court->id, $courtNumber, $data['booking_date'], $start, $end)) {
            throw ValidationException::withMessages([
                $field('start_time') => [__('messages.booking_overlap')],
            ]);
        }
    }

    protected function overlaps(mixed $courtId, int $courtNumber, string $date, int $start, int $end): bool
    {
        /** @var Collection<int, Booking> $existing */
        $existing = Booking::query()
            ->where('court_id', $courtId)
            ->where('court_number', $courtNumber)
            ->whereDate('booking_date', $date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->get();

        foreach ($existing as $booking) {
            $bStart = $this->toMinutes($booking->start_time);
            $bEnd = $bStart + (int) round(((float) $booking->hours) * 60);
            if ($start < $bEnd && $end > $bStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function parseOperatingHours(?string $hours): array
    {
        if ($hours && preg_match('/(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})/u', $hours, $m)) {
            $open = ((int) $m[1]) * 60 + (int) $m[2];
            $close = ((int) $m[3]) * 60 + (int) $m[4];
            if ($close <= $open) {
                $close = 24 * 60;
            }

            return [$open, $close];
        }

        return [6 * 60, 22 * 60];
    }

    public function minHoursForDate(string $date): float
    {
        return Carbon::parse($date, self::TZ)->isWeekend() ? 2.0 : 1.0;
    }

    protected function toMinutes(mixed $time): int
    {
        $label = $this->formatTime($time);
        [$h, $m] = array_map('intval', explode(':', $label));

        return $h * 60 + $m;
    }

    protected function formatTime(mixed $time): string
    {
        if ($time instanceof Carbon) {
            return $time->format('H:i');
        }

        $raw = substr((string) $time, 0, 5);

        return $raw !== '' ? $raw : '00:00';
    }
}
