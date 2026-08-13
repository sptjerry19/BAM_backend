<?php

namespace App\Services\Stats;

use App\Models\Booking;
use App\Models\Court;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;

class StatsService
{
    public function dashboard(): array
    {
        $memberSpend = (int) Member::sum('total_cost');
        $orderRevenue = (int) Order::whereIn('status', ['paid', 'shipped'])->sum('total');
        $bookingRevenue = (int) Booking::where('status', 'confirmed')->sum('total_price');

        return [
            'totalRevenue' => $memberSpend + $orderRevenue + $bookingRevenue,
            'activeMembers' => Member::where('status', 'active')->count(),
            'openCourts' => Court::where('status', '!=', 'closed')->count(),
            'totalMatches' => (int) Member::sum('matches'),
            'memberCount' => Member::count(),
            'courtCount' => Court::count(),
            'productCount' => Product::count(),
            'activeMembersList' => Member::where('status', 'active')->take(12)->get(),
            'courtStatuses' => Court::select('id', 'name', 'status')->take(12)->get(),
            'featuredProducts' => Product::where('featured', true)->take(12)->get(),
        ];
    }
}
