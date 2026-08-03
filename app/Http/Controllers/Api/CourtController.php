<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Court;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourtController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Court::query()->orderBy('name');

        if ($request->filled('per_page')) {
            return $this->success($query->paginate((int) $request->integer('per_page', 15)));
        }

        return $this->success($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|string|unique:courts,id',
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'district' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'surfaces' => 'required|string|max:255',
            'courts' => 'required|integer|min:1',
            'status' => 'required|in:open,busy,closed',
            'rating' => 'nullable|numeric|min:0|max:5',
            'image' => 'nullable|string|max:255',
            'x' => 'nullable|numeric',
            'y' => 'nullable|numeric',
            'phone' => 'nullable|string|max:50',
            'hours' => 'nullable|string|max:100',
        ]);

        return $this->success(Court::create($validated), __('messages.created'), 201);
    }

    public function show(string $id): JsonResponse
    {
        return $this->success(Court::findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $court = Court::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string|max:255',
            'district' => 'sometimes|string|max:255',
            'price' => 'sometimes|integer|min:0',
            'surfaces' => 'sometimes|string|max:255',
            'courts' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:open,busy,closed',
            'rating' => 'nullable|numeric|min:0|max:5',
            'image' => 'nullable|string|max:255',
            'x' => 'nullable|numeric',
            'y' => 'nullable|numeric',
            'phone' => 'nullable|string|max:50',
            'hours' => 'nullable|string|max:100',
        ]);

        $court->update($validated);

        return $this->success($court->fresh(), __('messages.updated'));
    }

    public function destroy(string $id): JsonResponse
    {
        Court::findOrFail($id)->delete();

        return $this->success(null, __('messages.deleted'));
    }
}
