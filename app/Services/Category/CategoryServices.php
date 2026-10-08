<?php

namespace App\Services\Category;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CategoryServices
{
    /**
     * @return Collection<int, Category>
     */
    public function list(array $filters = []): Collection
    {
        return Category::query()->orderBy('name')->get();
    }

    public function findOrFail(string $id): Category
    {
        return Category::findOrFail($id);
    }

    public function create(array $data): Category
    {
        $data['image'] = $data['image'] ?? '';

        return Category::create($data);
    }

    public function update(Category $category, array $data): Category
    {
        $category->update($data);

        return $category->fresh();
    }

    public function delete(Category $category): void
    {
        if ($category->news()->exists()) {
            throw ValidationException::withMessages([
                'id' => [__('messages.category_in_use')],
            ]);
        }

        $category->delete();
    }
}
