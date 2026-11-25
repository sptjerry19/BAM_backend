<?php

namespace App\Services\ContentStudio\Sources;

use App\Models\Product;
use Carbon\CarbonInterface;

/**
 * Turns a row of the shop's `products` table into FACTS the planner may use.
 *
 * Reality check (07/11/2026): the table is a retailer import. Descriptions are
 * "Name\n\nNguồn: <url>", only a handful carry a weight, none carry balance or
 * flex, and prices are only as fresh as the last import. So this provider:
 *  - exposes only values that are literally present (no inference),
 *  - hides the price when it is 0 or older than equipment.price_max_age_days,
 *  - reports which specs are missing instead of letting anyone fill them in,
 *  - never exposes product photos (rights are not established).
 * Product mode therefore only switches on when enough real facts exist.
 */
final class EquipmentProvider
{
    /** fact key => accepted labels (lower-case) */
    private const LABELS = [
        'weight' => ['trọng lượng', 'khối lượng'],
        'length' => ['chiều dài'],
        'balance' => ['điểm cân bằng'],
        'flex' => ['độ cứng'],
        'material' => ['chất liệu', 'vật liệu'],
    ];

    /** @return array<string, mixed> */
    public function factsFor(Product $product, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $description = (string) $product->description;
        $facts = $this->parseFacts($description);

        $maxAge = (int) config('content-studio.equipment.price_max_age_days');
        $syncedAt = $product->updated_at;
        $fresh = $syncedAt !== null && $syncedAt->greaterThanOrEqualTo($now->copy()->subDays($maxAge));
        $visible = (int) $product->price > 0 && $fresh;

        return [
            'id' => 'p'.$product->id,
            'product_id' => (int) $product->id,
            'name' => (string) $product->name,
            'brand' => (string) $product->brand,
            'category' => (string) $product->category,
            'source_url' => $this->sourceUrl($description),
            'in_stock' => (int) $product->stock > 0,
            'facts' => $facts,
            'missing_specs' => array_values(array_diff(array_keys(self::LABELS), array_keys($facts))),
            'price' => [
                'visible' => $visible,
                'value_vnd' => $visible ? (int) $product->price : null,
                'synced_at' => $syncedAt?->toIso8601String(),
            ],
        ];
    }

    /**
     * Rackets that have enough real facts to be discussed one by one.
     *
     * @return list<array<string, mixed>>
     */
    public function productModeCandidates(int $limit = 5, ?CarbonInterface $now = null): array
    {
        $min = (int) config('content-studio.equipment.min_facts_for_product_mode');

        $rows = Product::query()
            ->where('category', 'racket')
            ->where('price', '>', 0)
            ->where('stock', '>', 0)
            ->where(function ($q) {
                foreach (array_merge(...array_values(self::LABELS)) as $label) {
                    $q->orWhere('description', 'like', '%'.$label.'%');
                }
            })
            ->orderByDesc('sold_count')
            ->limit(300)
            ->get();

        $candidates = [];
        foreach ($rows as $product) {
            $facts = $this->factsFor($product, $now);
            if (count($facts['facts']) >= $min) {
                $candidates[] = $facts;
            }
            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /** @return array<string, string> */
    private function parseFacts(string $description): array
    {
        $facts = [];
        foreach (preg_split('/\R/u', $description) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$label, $value] = array_map('trim', explode(':', $line, 2));
            $label = mb_strtolower($label);
            $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
            if ($value === '' || mb_strlen($value) > 80 || str_starts_with($value, 'http')) {
                continue;
            }
            foreach (self::LABELS as $key => $labels) {
                if (in_array($label, $labels, true) && ! isset($facts[$key])) {
                    $facts[$key] = $value;
                }
            }
        }

        return $facts;
    }

    private function sourceUrl(string $description): ?string
    {
        return preg_match('/Nguồn:\s*(https?:\/\/\S+)/u', $description, $m) === 1 ? $m[1] : null;
    }
}
