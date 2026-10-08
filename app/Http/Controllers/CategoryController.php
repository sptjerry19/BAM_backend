<?php

namespace App\Http\Controllers;

use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Services\Category\CategoryServices;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryServices $category)
    {
    }

    public function index(): JsonResponse
    {
        return $this->success($this->category->list());
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        return $this->success(
            $this->category->create($request->validated()),
            __('messages.created'),
            201
        );
    }

    public function show(string $id): JsonResponse
    {
        return $this->success($this->category->findOrFail($id));
    }

    public function update(UpdateCategoryRequest $request, string $id): JsonResponse
    {
        $category = $this->category->findOrFail($id);

        return $this->success(
            $this->category->update($category, $request->validated()),
            __('messages.updated')
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->category->delete($this->category->findOrFail($id));

        return $this->success(null, __('messages.deleted'));
    }
}
