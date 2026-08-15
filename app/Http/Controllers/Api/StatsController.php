<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Stats\StatsService;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function __construct(private readonly StatsService $stats)
    {
    }

    public function index(): JsonResponse
    {
        return $this->success($this->stats->dashboard());
    }
}
