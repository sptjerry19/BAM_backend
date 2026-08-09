<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-seed products for load testing.
 *
 * Usage:
 *   php artisan db:seed --class=ProductMassSeeder
 *   PRODUCT_SEED_COUNT=100000 php artisan db:seed --class=ProductMassSeeder
 *
 * Default: 1_000_000 rows. Expect several minutes depending on DB.
 * Prefer MySQL/MariaDB over SQLite for this volume.
 */
class ProductMassSeeder extends Seeder
{
    private const DEFAULT_COUNT = 1_000_000;

    private const CHUNK_SIZE = 1_000;

    private const BRANDS = [
        'Yonex', 'Victor', 'Li-Ning', 'Kawasaki', 'Apacs',
        'Flypower', 'Mizuno', 'Babolat', 'Carlton', 'Felet',
    ];

    private const CATEGORIES = [
        'racket', 'shoes', 'shirt', 'shorts', 'shuttlecock', 'bag', 'grip', 'string',
    ];

    private const NAME_PREFIX = [
        'racket' => ['Astrox', 'Thruster', 'Aeronaut', 'Nanoflare', 'Jetspeed', 'Brave Sword'],
        'shoes' => ['Power Cushion', 'SH-A', 'Ranger', 'Sky Flash', 'Court Ace'],
        'shirt' => ['Tournament Tee', 'Pro Shirt', 'Match Jersey', 'Training Top'],
        'shorts' => ['Pro Shorts', 'Match Shorts', 'Training Shorts'],
        'shuttlecock' => ['AS-50', 'Master Ace', 'Tournament Cork', 'Club Nylon'],
        'bag' => ['Pro Bag', 'Tour Bag', 'Club Bag', 'Racket Tote'],
        'grip' => ['AC Grip', 'Dry Grip', 'Tacky Wrap', 'Overgrip Pro'],
        'string' => ['BG65', 'BG80', 'VBS Power', 'Aerobite'],
    ];

    private const IMAGES = [
        'photo-1626224583764-f87db24ac4ea',
        'photo-1617802690992-15d93263d3a9',
        'photo-1617802690992-15d93263d3a9',
        'photo-1554068865-24cecd4e340b',
    ];

    public function run(): void
    {
        $total = max(1, (int) (env('PRODUCT_SEED_COUNT', self::DEFAULT_COUNT)));
        $chunk = max(100, min(5000, (int) (env('PRODUCT_SEED_CHUNK', self::CHUNK_SIZE))));
        $now = now()->toDateTimeString();

        $this->command?->warn("Seeding {$total} products in chunks of {$chunk}...");
        $started = microtime(true);

        DB::connection()->disableQueryLog();

        $rows = [];
        $inserted = 0;

        for ($i = 1; $i <= $total; $i++) {
            $category = self::CATEGORIES[($i - 1) % count(self::CATEGORIES)];
            $brand = self::BRANDS[($i - 1) % count(self::BRANDS)];
            $prefixPool = self::NAME_PREFIX[$category];
            $prefix = $prefixPool[($i - 1) % count($prefixPool)];
            $imageKey = self::IMAGES[($i - 1) % count(self::IMAGES)];
            $imageUrl = "https://images.unsplash.com/{$imageKey}?w=400&h=300&fit=crop";

            $basePrice = match ($category) {
                'racket' => 800_000 + ($i % 50) * 70_000,
                'shoes' => 600_000 + ($i % 40) * 40_000,
                'shirt', 'shorts' => 200_000 + ($i % 30) * 15_000,
                'shuttlecock' => 120_000 + ($i % 20) * 10_000,
                'bag' => 400_000 + ($i % 35) * 25_000,
                'grip' => 40_000 + ($i % 15) * 5_000,
                'string' => 80_000 + ($i % 25) * 8_000,
                default => 100_000,
            };

            $hasDiscount = ($i % 7) === 0;
            $price = $hasDiscount ? (int) round($basePrice * 0.85) : $basePrice;

            $rows[] = [
                'name' => "{$brand} {$prefix} #{$i}",
                'brand' => $brand,
                'category' => $category,
                'price' => $price,
                'original_price' => $hasDiscount ? $basePrice : null,
                'image' => $imageUrl,
                'images' => json_encode([$imageUrl], JSON_UNESCAPED_SLASHES),
                'stock' => ($i % 101),
                'rating' => round(3.5 + (($i % 16) * 0.1), 1),
                'reviews' => ($i % 500),
                'description' => "Sản phẩm {$category} {$brand} {$prefix} phục vụ kiểm thử hiệu năng (#{$i}).",
                'featured' => ($i % 50) === 0 ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= $chunk) {
                DB::table('products')->insert($rows);
                $inserted += count($rows);
                $rows = [];
                $this->reportProgress($inserted, $total, $started);
            }
        }

        if ($rows !== []) {
            DB::table('products')->insert($rows);
            $inserted += count($rows);
            $this->reportProgress($inserted, $total, $started);
        }

        $seconds = round(microtime(true) - $started, 2);
        $this->command?->info("Done: inserted {$inserted} products in {$seconds}s.");
    }

    private function reportProgress(int $inserted, int $total, float $started): void
    {
        if ($inserted % (self::CHUNK_SIZE * 10) !== 0 && $inserted !== $total) {
            return;
        }

        $pct = round(($inserted / $total) * 100, 1);
        $elapsed = max(0.001, microtime(true) - $started);
        $rate = (int) round($inserted / $elapsed);
        $this->command?->line("  → {$inserted}/{$total} ({$pct}%) · ~{$rate} rows/s");
    }
}
