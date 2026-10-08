<?php

namespace App\Services\News;

use App\Models\News;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NewsService
{
    public function list(array $filters)
    {
        try {
            $perPage = max(1, min((int) ($filters['per_page'] ?? 10), 100));
            $query = News::query()
                ->with(['category:id,name,image', 'user:id,name,role'])
                ->orderByDesc('featured')
                ->orderByDesc('date')
                ->orderByDesc('id');

            if (! empty($filters['category_id'])) {
                $query->where('category_id', $filters['category_id']);
            }

            return $query->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('News list failed: ' . $e->getMessage());
            return false;
        }
    }

    public function findOrFail(string $id): News
    {
        return News::query()
            ->with(['category:id,name,image', 'user:id,name,role'])
            ->findOrFail($id);
    }

    public function create(array $data)
    {
        try {
            $data['user_id'] = auth()->id();
            $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title']);
            $data['date'] = $data['date'] ?? now()->toDateString();
            $data['featured'] = (bool) ($data['featured'] ?? false);
            $data['content'] = $data['content'] ?? '';
            $data['body'] = $data['body'] ?? $data['content'];
            $data['image'] = $data['image'] ?? '';

            $news = News::create($data);
            return $this->findOrFail((string) $news->id);
        } catch (\Exception $e) {
            Log::error('News create failed: ' . $e->getMessage());
            return false;
        }
    }

    public function update(News $news, array $data)
    {
        try {
            if (isset($data['title']) && empty($data['slug'])) {
                $data['slug'] = $this->uniqueSlug($data['title'], $news->id);
            } elseif (! empty($data['slug'])) {
                $data['slug'] = $this->uniqueSlug($data['slug'], $news->id);
            }

            if (array_key_exists('content', $data) && empty($data['body'])) {
                $data['body'] = $data['content'];
            }

            if (array_key_exists('featured', $data)) {
                $data['featured'] = (bool) $data['featured'];
            }

            $news->update($data);
            return $this->findOrFail((string) $news->id);
        } catch (\Exception $e) {
            Log::error('News update failed: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(News $news): void
    {
        $news->delete();
    }

    protected function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'bai-viet';
        $slug = $base;
        $i = 2;

        while (
            News::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
