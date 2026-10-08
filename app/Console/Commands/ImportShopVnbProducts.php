<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportShopVnbProducts extends Command
{
    protected $signature = 'products:import-shopvnb
                            {--file=scripts/data/shopvnb_rackets.json : Path to crawled JSON}
                            {--fresh : Delete previously imported ShopVNB products before import}
                            {--chunk=200 : Insert chunk size}';

    protected $description = 'Import racket products crawled from ShopVNB into the products table';

    public function handle(): int
    {
        $relative = (string) $this->option('file');
        $path = File::isFile($relative) ? $relative : base_path($relative);

        if (! File::isFile($path)) {
            $this->error("JSON not found: {$path}");

            return self::FAILURE;
        }

        $payload = json_decode(File::get($path), true);
        if (! is_array($payload) || ! isset($payload['products']) || ! is_array($payload['products'])) {
            $this->error('Invalid JSON payload (expected { products: [...] }).');

            return self::FAILURE;
        }

        /** @var list<array<string, mixed>> $products */
        $products = $payload['products'];
        $this->info('Source: '.($payload['source'] ?? 'unknown'));
        $this->info('Products in file: '.count($products));

        $idIsString = $this->productsIdIsString();

        if ($this->option('fresh')) {
            $deleted = $this->deletePreviousImports($idIsString);
            $this->warn("Deleted {$deleted} previously imported ShopVNB products");
        }

        $now = now()->toDateTimeString();
        $chunkSize = max(50, (int) $this->option('chunk'));
        $rows = [];
        $skipped = 0;

        foreach ($products as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $brand = trim((string) ($item['brand'] ?? ''));
            $price = (int) ($item['price'] ?? 0);
            $externalId = trim((string) ($item['id'] ?? ''));

            if ($name === '' || $brand === '' || $price < 0) {
                $skipped++;

                continue;
            }

            $images = $item['images'] ?? [];
            if (! is_array($images)) {
                $images = [];
            }
            $images = array_values(array_filter(array_map(
                static fn ($url) => is_string($url) ? trim($url) : '',
                $images
            )));

            $image = is_string($item['image'] ?? null) ? trim((string) $item['image']) : null;
            if (($image === null || $image === '') && $images !== []) {
                $image = $images[0];
            }

            $original = $item['original_price'] ?? null;
            $original = $original === null || $original === '' ? null : (int) $original;
            if ($original !== null && $original <= $price) {
                $original = null;
            }

            $sourceUrl = trim((string) ($item['source_url'] ?? ''));
            $description = trim((string) ($item['description'] ?? $name));
            if ($sourceUrl !== '' && ! str_contains($description, $sourceUrl)) {
                $description = trim($description."\n\nNguồn: ".$sourceUrl);
            }

            $row = [
                'name' => mb_substr($name, 0, 255),
                'brand' => mb_substr($brand, 0, 255),
                'category' => 'racket',
                'price' => $price,
                'original_price' => $original,
                'image' => $image,
                'images' => json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'stock' => max(0, (int) ($item['stock'] ?? 25)),
                'rating' => (float) ($item['rating'] ?? 0),
                'reviews' => max(0, (int) ($item['reviews'] ?? 0)),
                'description' => $description,
                'featured' => ! empty($item['featured']) ? 1 : 0,
                'sold_count' => max(0, (int) ($item['sold_count'] ?? 0)),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($idIsString) {
                if ($externalId === '') {
                    $skipped++;

                    continue;
                }
                $row['id'] = $externalId;
            }

            $rows[] = $row;
        }

        $inserted = 0;
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            if ($idIsString) {
                DB::table('products')->upsert(
                    $chunk,
                    ['id'],
                    [
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
                        'description',
                        'featured',
                        'sold_count',
                        'updated_at',
                    ]
                );
            } else {
                DB::table('products')->insert($chunk);
            }
            $inserted += count($chunk);
            $this->line("  imported {$inserted}/".count($rows));
        }

        $this->info("Done. imported={$inserted} skipped={$skipped}");
        $this->info('Racket count now: '.DB::table('products')->where('category', 'racket')->count());

        return self::SUCCESS;
    }

    private function productsIdIsString(): bool
    {
        $column = collect(DB::select('SHOW COLUMNS FROM products WHERE Field = ?', ['id']))->first();
        if (! $column) {
            return false;
        }

        $type = strtolower((string) $column->Type);

        return str_contains($type, 'char') || str_contains($type, 'text');
    }

    private function deletePreviousImports(bool $idIsString): int
    {
        return DB::table('products')->where(function ($q) use ($idIsString) {
            $q->where('image', 'like', '%shopvnb.com%')
                ->orWhere('description', 'like', '%shopvnb.com%');
            if ($idIsString) {
                $q->orWhere('id', 'like', 'vnb_%');
            }
        })->delete();
    }
}
