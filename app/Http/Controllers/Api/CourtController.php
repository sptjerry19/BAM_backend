<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Court\IndexCourtRequest;
use App\Http\Requests\Court\StoreCourtRequest;
use App\Http\Requests\Court\UpdateCourtRequest;
use App\Services\Court\CourtService;
use Illuminate\Http\JsonResponse;

class CourtController extends Controller
{
    public function __construct(private readonly CourtService $courts)
    {
    }

    public function index(IndexCourtRequest $request): JsonResponse
    {
        return $this->success($this->courts->list($request->validated()));
    }

    public function store(StoreCourtRequest $request): JsonResponse
    {
        return $this->success(
            $this->courts->create($request->validated()),
            __('messages.created'),
            201
        );
    }

    public function show(string $id): JsonResponse
    {
        return $this->success($this->courts->findOrFail($id));
    }

    public function update(UpdateCourtRequest $request, string $id): JsonResponse
    {
        $court = $this->courts->findOrFail($id);

        return $this->success(
            $this->courts->update($court, $request->validated()),
            __('messages.updated')
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->courts->delete($this->courts->findOrFail($id));

        return $this->success(null, __('messages.deleted'));
    }
}
