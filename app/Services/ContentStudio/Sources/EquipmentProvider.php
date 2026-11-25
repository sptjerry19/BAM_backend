<?php

namespace App\Services\ContentStudio\Sources;

use App\Models\ContentStudio\ContentItemProduct;
use App\Models\ContentStudio\RacketSpec;
use App\Models\Product;
use App\Services\ContentStudio\Sources\Specs\SpecMatcher;
use Carbon\CarbonInterface;

/**
 * Turns a row of the shop's `products` table into FACTS the planner may use.
 *
 * Reality check (07/11/2026): the table is a retailer import. Descriptions are
 * "Name\n\nNguồn: <url>", only a handful carry a weight, none carry balance or
 * flex, and prices are only as fresh as the last import. So this provider:
 *  - exposes only values that are literally present (no inference), either in the
 *    shop description or on the manufacturer page SpecMatcher links with certainty
 *    (`racket_specs`); the manufacturer's value wins when both have one,
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
    public function factsFor(Product $product, ?CarbonInterface $now = null, ?SpecMatcher $specs = null): array
    {
        $now ??= now();
        $description = (string) $product->description;
        $facts = $this->parseFacts($description);

        $spec = ($specs ?? new SpecMatcher)->match($product);
        if ($spec !== null) {
            $facts = array_merge($facts, $this->specFacts($spec));
        }

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
            // the manufacturer page the specs came from, so a reviewer can check the match
            'spec_source_url' => $spec?->source_url,
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
     * Rackets that have enough real facts to be discussed one by one and have not been
     * the subject of a post yet. When none are left the planner falls back to guide mode
     * (offering a used racket again would only be rejected as a duplicate fingerprint).
     *
     * @return list<array<string, mixed>>
     */
    public function productModeCandidates(int $limit = 5, ?CarbonInterface $now = null): array
    {
        $min = (int) config('content-studio.equipment.min_facts_for_product_mode');
        $specs = new SpecMatcher;
        $specBrands = $specs->brands();

        $rows = Product::query()
            ->where('category', 'racket')
            ->where('price', '>', 0)
            ->where('stock', '>', 0)
            ->whereNotIn('id', ContentItemProduct::query()->select('product_id'))
            ->where(function ($q) use ($specBrands) {
                foreach (array_merge(...array_values(self::LABELS)) as $label) {
                    $q->orWhere('description', 'like', '%'.$label.'%');
                }
                foreach ($specBrands as $brand) {
                    $q->orWhereRaw('LOWER(brand) = ?', [$brand]);
                }
            })
            ->orderByDesc('sold_count')
            ->orderBy('id')
            ->lazy(300);

        $candidates = [];
        $offeredSpecs = [];
        foreach ($rows as $product) {
            $facts = $this->factsFor($product, $now, $specs);
            $page = $facts['spec_source_url'];
            // two listings of the same racket must not both be offered
            if (count($facts['facts']) < $min || ($page !== null && isset($offeredSpecs[$page]))) {
                continue;
            }
            if ($page !== null) {
                $offeredSpecs[$page] = true;
            }
            $candidates[] = $facts;
            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * Rows of a manufacturer's table, under our fact keys, values verbatim. Labels as
     * seen on yonex.com and victorsport.com (checked 26/11/2026).
     *
     * @return array<string, string>
     */
    private function specFacts(RacketSpec $spec): array
    {
        $table = (array) $spec->specs_json;
        $facts = [];

        $weight = $table['Weight / Grip'] ?? $table['Weight / Grip Size'] ?? $table['Weight'] ?? null;
        if ($weight !== null) {
            $facts['weight'] = (string) $weight;
        }
        if (isset($table['Flex'])) {
            $facts['flex'] = (string) $table['Flex'];
        }
        // Yonex "Length" is relative ("10 mm longer"): only an absolute length is a fact.
        if (preg_match('/^\d{3}\s?mm$/i', (string) ($table['Length'] ?? '')) === 1) {
            $facts['length'] = (string) $table['Length'];
        }
        $material = array_filter([
            'Khung' => $table['Frame'] ?? $table['Frame Material'] ?? null,
            'Thân' => $table['Shaft'] ?? $table['Shaft Material'] ?? null,
        ]);
        if ($material !== []) {
            $facts['material'] = implode('; ', array_map(fn ($part, $value) => "{$part}: {$value}", array_keys($material), $material));
        }

        return $facts;
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
