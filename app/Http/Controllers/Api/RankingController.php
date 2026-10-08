<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ranking\IndexRankingRequest;
use App\Services\Ranking\RankingService;
use Illuminate\Http\JsonResponse;

class RankingController extends Controller
{
    public function __construct(private readonly RankingService $rankings)
    {
    }

    public function index(IndexRankingRequest $request): JsonResponse
    {
        $limit = (int) ($request->validated()['limit'] ?? 20);

        return $this->success($this->rankings->boards($limit));
    }

    public function show(string $athlete): JsonResponse
    {
        return $this->success($this->rankings->athlete($athlete));
    }
}
