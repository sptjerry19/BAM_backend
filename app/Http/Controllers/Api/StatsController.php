<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function index(): JsonResponse
    {
        $memberSpend = (int) Member::sum('total_cost');
        $orderRevenue = (int) Order::whereIn('status', ['paid', 'shipped'])->sum('total');
        $bookingRevenue = (int) Booking::where('status', 'confirmed')->sum('total_price');

        return $this->success([
            'totalRevenue' => $memberSpend + $orderRevenue + $bookingRevenue,
            'activeMembers' => Member::where('status', 'active')->count(),
            'openCourts' => Court::where('status', '!=', 'closed')->count(),
            'totalMatches' => (int) Member::sum('matches'),
            'memberCount' => Member::count(),
            'courtCount' => Court::count(),
            'productCount' => Product::count(),
            'activeMembersList' => Member::where('status', 'active')->get(),
            'courtStatuses' => Court::select('id', 'name', 'status')->get(),
            'featuredProducts' => Product::where('featured', true)->get(),
        ]);
    }
}
