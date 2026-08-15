<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Services\Storage\FileUploadService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Matchish\ScoutElasticSearch\Engines\ElasticSearchEngine;
use Throwable;

class ProductService
{
    private const LIST_COUNT_TTL_SECONDS = 60;

    /** Must stay <= the index `max_result_window` in config/elasticsearch.php. */
    private const SEARCH_RESULT_CAP = 50000;

    private const LIST_COLUMNS = [
        'id',
        'name',
        'brand',
        'category',
        'price',
        'original_price',
        'image',
        'images',
        'stock',
        'rating',
        'reviews',
        'featured',
        'sold_count',
        'created_at',
    ];

    public function __construct(private readonly FileUploadService $uploads)
    {
    }

    /**
     * Catalog list. Text search uses OpenSearch (Scout) when configured;
     * browse-without-search stays on MySQL + cached COUNT(*).
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(
            (int) ($filters['per_page'] ?? config('const.limit', 12)),
            100
        ));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $search = trim((string) ($filters['search'] ?? ''));
        $category = ! empty($filters['category']) ? (string) $filters['category'] : null;

        if ($search !== '' && $this->openSearchEnabled()) {
            try {
                return $this->listViaOpenSearch($search, $category, $perPage, $page);
            } catch (Throwable $e) {
                // A degraded search beats a broken catalog page.
                Log::warning('Elasticsearch product search failed, falling back to MySQL', [
                    'search' => $search,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $query = Product::query()->select(self::LIST_COLUMNS);

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('brand', 'like', '%'.$search.'%');
            });
        }

        if ($category !== null) {
            $query->where('category', $category);
        }

        // Stable order → PK/index friendly; avoids filesort on large catalogs.
        $query->orderByDesc('id');

        $total = $this->resolveListTotal(clone $query, $search, $category);

        $items = $query->forPage($page, $perPage)->get();

        return new Paginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );
    }

    private function openSearchEnabled(): bool
    {
        // Name kept for BC — means Scout Elasticsearch engine is configured
        return config('scout.driver') === ElasticSearchEngine::class
            && filled(config('elasticsearch.host'));
    }

    private function listViaOpenSearch(
        string $search,
        ?string $category,
        int $perPage,
        int $page
    ): LengthAwarePaginator {
        $page = min($page, max(1, intdiv(self::SEARCH_RESULT_CAP, $perPage)));

        $builder = Product::search($search, function ($client, $body) use ($search, $category) {
            $window = $body->toArray();

            return $client->search([
                'index' => (new Product)->searchableAs(),
                'body' => array_filter([
                    'query' => $this->searchQuery($search, $category),
                    'track_total_hits' => self::SEARCH_RESULT_CAP,
                    'from' => $window['from'] ?? null,
                    'size' => $window['size'] ?? null,
                ], static fn ($value) => $value !== null),
            ])->asArray();
        });

        /** @var LengthAwarePaginator $results */
        $results = $builder->paginate($perPage, 'page', $page);

        return $results;
    }

    /**
     * Scout's default engine sends the raw term as a `query_string`, so a shopper
     * typing `"`, `+` or `AND` triggers an Elasticsearch parse error.
     *
     * @return array<string, mixed>
     */
    private function searchQuery(string $search, ?string $category): array
    {
        $bool = [
            'should' => [
                [
                    'multi_match' => [
                        'query' => $search,
                        'type' => 'best_fields',
                        'fields' => ['name^5', 'brand^3', 'category^2', 'description'],
                        'operator' => 'and',
                        'fuzziness' => 'AUTO',
                        'prefix_length' => 1,
                    ],
                ],
                [
                    // Keeps results useful mid-word: "astro" still finds "Astrox".
                    'multi_match' => [
                        'query' => $search,
                        'type' => 'phrase_prefix',
                        'fields' => ['name^6', 'brand^4'],
                    ],
                ],
            ],
            'minimum_should_match' => 1,
        ];

        if ($category !== null) {
            $bool['filter'] = [['term' => ['category' => $category]]];
        }

        return [
            'function_score' => [
                'query' => ['bool' => $bool],
                'functions' => [
                    [
                        'field_value_factor' => [
                            'field' => 'sold_count',
                            'modifier' => 'log1p',
                            'missing' => 0,
                        ],
                        'weight' => 0.3,
                    ],
                ],
                'score_mode' => 'sum',
                'boost_mode' => 'sum',
            ],
        ];
    }

    /**
     * COUNT(*) on full table is expensive; cache when filters are stable (no free-text search).
     */
    private function resolveListTotal(Builder $query, string $search, ?string $category): int
    {
        if ($search !== '') {
            return (int) $query->toBase()->getCountForPagination();
        }

        $key = $this->listCountCacheKey($category);

        return (int) Cache::remember(
            $key,
            self::LIST_COUNT_TTL_SECONDS,
            static fn () => (int) $query->toBase()->getCountForPagination()
        );
    }

    private function listCountCacheKey(?string $category): string
    {
        return 'products:list_count:'.($category ?: 'all');
    }

    private function forgetListCountCache(?string $category = null): void
    {
        Cache::forget($this->listCountCacheKey(null));

        $categories = [
            'racket', 'shoes', 'shirt', 'shorts', 'shuttlecock', 'bag', 'grip', 'string',
        ];

        if ($category !== null) {
            Cache::forget($this->listCountCacheKey($category));

            return;
        }

        foreach ($categories as $cat) {
            Cache::forget($this->listCountCacheKey($cat));
        }
    }

    public function findOrFail(string $id): Product
    {
        return Product::findOrFail($id);
    }

    /**
     * @return Collection<int, Product>
     */
    public function bestsellers(int $limit = 5): Collection
    {
        $limit = max(1, min($limit, 20));

        return Product::query()
            ->select(self::LIST_COLUMNS)
            ->orderByDesc('sold_count')
            ->orderByDesc('featured')
            ->limit($limit)
            ->get();
    }

    public function create(array $data, Request $request): Product
    {
        $imageUrls = $this->resolveImageUrls(
            $request,
            $data['images'] ?? [],
            'products'
        );

        $product = Product::create([
            'id' => $data['id'] ?? ('p_'.Str::lower(Str::random(10))),
            'name' => $data['name'],
            'brand' => $data['brand'],
            'category' => $data['category'],
            'price' => $data['price'],
            'original_price' => $data['original_price'] ?? null,
            'images' => $imageUrls,
            'image' => $imageUrls[0] ?? ($data['image'] ?? null),
            'stock' => $data['stock'],
            'rating' => $data['rating'] ?? 0,
            'reviews' => $data['reviews'] ?? 0,
            'description' => $data['description'] ?? null,
            'featured' => filter_var($data['featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);

        $this->forgetListCountCache($product->category);

        return $product;
    }

    public function update(Product $product, array $data, Request $request): Product
    {
        $touchingImages = $request->hasFile('image_files')
            || $request->has('keep_images')
            || array_key_exists('images', $data);

        $previousCategory = $product->category;

        if ($touchingImages) {
            $kept = $request->input('keep_images', $product->images ?? []);
            if (is_string($kept)) {
                $decoded = json_decode($kept, true);
                $kept = is_array($decoded) ? $decoded : [$kept];
            }

            $uploaded = [];
            $files = $request->file('image_files', []);
            if ($files instanceof UploadedFile) {
                $files = [$files];
            }
            if (is_array($files) && count($files) > 0) {
                $uploaded = $this->uploads->uploadManyUrls($files, 'products');
            }

            $fromPayload = $this->normalizeUrlList($data['images'] ?? []);
            $imageUrls = array_values(array_unique(array_filter([
                ...$this->normalizeUrlList($kept),
                ...$fromPayload,
                ...$uploaded,
            ])));

            $removed = array_diff($product->images ?? [], $imageUrls);
            if ($removed) {
                $this->uploads->deleteMany(array_values($removed));
            }

            $data['images'] = $imageUrls;
            $data['image'] = $imageUrls[0] ?? null;
        }

        if (array_key_exists('featured', $data)) {
            $data['featured'] = filter_var($data['featured'], FILTER_VALIDATE_BOOLEAN);
        }

        unset($data['keep_images'], $data['image_files']);
        $product->update($data);

        $fresh = $product->fresh();
        $this->forgetListCountCache($previousCategory);
        if ($fresh && $fresh->category !== $previousCategory) {
            $this->forgetListCountCache($fresh->category);
        }

        return $fresh;
    }

    public function delete(Product $product): void
    {
        $category = $product->category;

        $this->uploads->deleteMany($product->images ?? []);

        if ($product->image && ! in_array($product->image, $product->images ?? [], true)) {
            $this->uploads->delete($product->image);
        }

        $product->delete();
        $this->forgetListCountCache($category);
    }

    /**
     * @param  array<int, mixed>  $existing
     * @return array<int, string>
     */
    private function resolveImageUrls(Request $request, array $existing, string $folder): array
    {
        $urls = $this->normalizeUrlList($existing);

        $files = $request->file('image_files', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        if (is_array($files) && count($files) > 0) {
            $urls = array_merge($urls, $this->uploads->uploadManyUrls($files, $folder));
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeUrlList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                return [trim($value)];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        $urls = [];
        foreach ($value as $item) {
            if ($item instanceof UploadedFile) {
                continue;
            }
            if (is_string($item) && $item !== '') {
                $urls[] = trim($item);
            }
        }

        return $urls;
    }
}
