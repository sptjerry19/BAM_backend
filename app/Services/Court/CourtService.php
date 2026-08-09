<?php

namespace App\Services\Court;

use App\Models\Court;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class CourtService
{
    /**
     * @return Collection<int, Court>|LengthAwarePaginator
     */
    public function list(array $filters): Collection|LengthAwarePaginator
    {
        $status = $filters['status'] ?? null;
        $query = Court::query()->orderBy('name');

        if (! empty($filters['per_page'])) {
            return $query->paginate((int) $filters['per_page']);
        }

        $user = auth()->user();
        if (! $user || $user->role !== 'admin') {
            if ($status) {
                $query->where('status', $status);
            }
            $query->limit(5);
        }

        return $query->get();
    }

    public function findOrFail(string $id): Court
    {
        return Court::findOrFail($id);
    }

    public function create(array $data): Court
    {
        return Court::create($data);
    }

    public function update(Court $court, array $data): Court
    {
        $court->update($data);

        return $court->fresh();
    }

    public function delete(Court $court): void
    {
        $court->delete();
    }
}
