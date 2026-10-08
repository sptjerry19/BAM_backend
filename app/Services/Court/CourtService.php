<?php

namespace App\Services\Court;

use App\Models\Court;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class CourtService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 9), 100));
        $query = Court::query()->orderBy('name');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Highest-rated courts for the booking hero. Not paginated.
     *
     * @return array{courts: Collection<int, Court>, summary: array{total: int, open: int, court_count: int, min_price: int}}
     */
    public function top(int $limit): array
    {
        $limit = max(1, min($limit, 20));

        $courts = Court::query()
            ->orderByDesc('rating')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $summary = Court::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status = 'open') as open_count")
            ->selectRaw('COALESCE(SUM(courts), 0) as court_count')
            ->selectRaw('COALESCE(MIN(price), 0) as min_price')
            ->first();

        return [
            'courts' => $courts,
            'summary' => [
                'total' => (int) ($summary->total ?? 0),
                'open' => (int) ($summary->open_count ?? 0),
                'court_count' => (int) ($summary->court_count ?? 0),
                'min_price' => (int) ($summary->min_price ?? 0),
            ],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Court>
     */
    public function picked(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return Court::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
    }

    public function findOrFail(string $id): Court
    {
        return Court::findOrFail($id);
    }

    public function create(array $data): Court
    {
        if (isset($data['id']) && ! is_numeric($data['id'])) {
            unset($data['id']);
        }

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
