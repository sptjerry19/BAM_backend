<?php

namespace App\Services\Ranking;

use App\Models\Booking;
use App\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RankingService
{
    /**
     * @return array{athletes: list<array<string, mixed>>, members: list<array<string, mixed>>}
     */
    public function boards(int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $members = Member::query()
            ->where('status', 'active')
            ->get();

        $monthStats = $this->monthBookingStats();

        return [
            'athletes' => $this->athletes($members, $limit),
            'members' => $this->members($members, $monthStats, $limit),
        ];
    }

    /**
     * @param  Collection<int, Member>  $members
     * @return list<array<string, mixed>>
     */
    private function athletes(Collection $members, int $limit): array
    {
        return $members
            ->sort(function (Member $a, Member $b) {
                return [(int) $b->points, (int) $b->wins, (string) $a->id]
                    <=> [(int) $a->points, (int) $a->wins, (string) $b->id];
            })
            ->take($limit)
            ->values()
            ->map(function (Member $member, int $index) {
                $matches = (int) $member->matches;
                $wins = (int) $member->wins;

                return [
                    'id' => (string) $member->id,
                    'rank' => $index + 1,
                    'name' => $member->name,
                    'handle' => Str::before((string) $member->email, '@'),
                    'club' => 'JBadminton',
                    'level' => $member->level,
                    'points' => (int) $member->points,
                    'win' => $wins,
                    'loss' => max(0, $matches - $wins),
                    'trend' => 'same',
                    'avatar' => $member->avatar,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, Member>  $members
     * @param  Collection<string|int, object>  $monthStats
     * @return list<array<string, mixed>>
     */
    private function members(Collection $members, Collection $monthStats, int $limit): array
    {
        return $members
            ->map(function (Member $member) use ($monthStats) {
                $stat = $monthStats->get($member->id) ?? $monthStats->get((string) $member->id);

                return [
                    'member' => $member,
                    'hours' => round((float) ($stat->hours ?? 0), 1),
                    'bookings' => (int) ($stat->bookings ?? 0),
                ];
            })
            ->sort(function (array $a, array $b) {
                return [
                    $b['hours'],
                    $b['bookings'],
                    (int) $b['member']->total_cost,
                    (string) $a['member']->id,
                ] <=> [
                    $a['hours'],
                    $a['bookings'],
                    (int) $a['member']->total_cost,
                    (string) $b['member']->id,
                ];
            })
            ->take($limit)
            ->values()
            ->map(function (array $row, int $index) {
                /** @var Member $member */
                $member = $row['member'];

                return [
                    'id' => (string) $member->id,
                    'rank' => $index + 1,
                    'name' => $member->name,
                    'level' => $member->level,
                    'hours' => $row['hours'],
                    'bookings' => $row['bookings'],
                    'spend' => (int) $member->total_cost,
                    'trend' => 'same',
                    'avatar' => $member->avatar,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function athlete(string $id): array
    {
        $member = Member::query()->where('status', 'active')->findOrFail($id);
        $board = $this->athletes(
            Member::query()->where('status', 'active')->get(),
            500
        );
        $card = collect($board)->firstWhere('id', (string) $member->id);

        $matches = (int) $member->matches;
        $wins = (int) $member->wins;
        $photos = array_values(array_filter([(string) $member->avatar]));

        return [
            'id' => (string) $member->id,
            'rank' => $card['rank'] ?? null,
            'name' => $member->name,
            'handle' => Str::before((string) $member->email, '@'),
            'club' => 'JBadminton',
            'level' => $member->level,
            'points' => (int) $member->points,
            'win' => $wins,
            'loss' => max(0, $matches - $wins),
            'trend' => 'same',
            'avatar' => $member->avatar,
            'cover' => $member->avatar,
            'since' => $member->join_date?->format('Y'),
            'friends' => 0,
            'followers' => 0,
            'following' => 0,
            'photos' => $photos,
            'posts' => [],
            'achievements' => [],
        ];
    }

    /**
     * @return Collection<string|int, object>
     */
    private function monthBookingStats(): Collection
    {
        return Booking::query()
            ->selectRaw('member_id, SUM(hours) as hours, COUNT(*) as bookings')
            ->whereNotNull('member_id')
            ->where('status', '!=', 'cancelled')
            ->whereDate('booking_date', '>=', now()->startOfMonth()->toDateString())
            ->groupBy('member_id')
            ->get()
            ->keyBy('member_id');
    }
}
