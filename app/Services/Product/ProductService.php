<?php

namespace App\Services\Product;

use App\Models\Order;
use App\Models\Product;
use App\Services\Storage\FileUploadService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ProductService
{
    public function __construct(private readonly FileUploadService $uploads)
    {
    }

    /**
     * @return Collection<int, Product>|LengthAwarePaginator
     */
    public function list(array $filters): Collection|LengthAwarePaginator
    {
        $query = Product::query();
        if (! empty($filters['search'])) {
            $query->where(function ($query) use ($filters) {
                $query->where('name', 'like', '%'.$filters['search'].'%');
            });
        }


        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? config('const.limit')));
    }

    public function findOrFail(string $id): Product
    {
        return Product::findOrFail($id);
    }

    /**
     * Top sellers by qty in paid/shipped orders; fill with featured/rating if short.
     *
     * @return Collection<int, Product>
     */
    public function bestsellers(int $limit = 5): Collection
    {
        $limit = max(1, min($limit, 20));

       $products = Product::orderBy('sold_count', 'desc')->limit($limit)->get();
       return $products;
    }

    public function create(array $data, Request $request): Product
    {
        $imageUrls = $this->resolveImageUrls(
            $request,
            $data['images'] ?? [],
            'products'
        );

        return Product::create([
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
    }

    public function update(Product $product, array $data, Request $request): Product
    {
        $touchingImages = $request->hasFile('image_files')
            || $request->has('keep_images')
            || array_key_exists('images', $data);

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

        return $product->fresh();
    }

    public function delete(Product $product): void
    {
        $this->uploads->deleteMany($product->images ?? []);

        if ($product->image && ! in_array($product->image, $product->images ?? [], true)) {
            $this->uploads->delete($product->image);
        }

        $product->delete();
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
