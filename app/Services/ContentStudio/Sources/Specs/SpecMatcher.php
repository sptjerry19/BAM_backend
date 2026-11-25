<?php

namespace App\Services\ContentStudio\Sources\Specs;

use App\Models\ContentStudio\RacketSpec;
use App\Models\Product;

/**
 * Links a shop product to ONE manufacturer spec page, by name, and only when there is
 * no doubt. Wrong specs in a post are worse than no specs, so every unclear case is a miss:
 *
 *  - the names must be equal after dropping only "Vợt cầu lông", the brand and "chính hãng"
 *    (series, year, colour, edition, set or combo words all count)
 *  - exactly one spec page may carry that name (Yonex lists two generations as "ASTROX 99 PRO")
 *  - the shop may not also sell a dated or "new" version of that name, because the undated
 *    listing and the manufacturer page could then be different generations
 *
 * Limit: if only one of two same-named pages has been imported and the shop lists no dated
 * version, the second generation cannot be detected here.
 *
 * Specs are indexed on first use: create a new instance per run.
 */
final class SpecMatcher
{
    /** @var array<string, array<string, list<RacketSpec>>>|null brand => name key => rows */
    private ?array $specs = null;

    /** @var array<string, list<string>> brand => name keys of the rackets the shop lists */
    private array $shopKeys = [];

    /** @return list<string> brands (lower-case) that have at least one imported spec page */
    public function brands(): array
    {
        return array_keys($this->specs());
    }

    public function match(Product $product): ?RacketSpec
    {
        if ($product->category !== 'racket') {
            return null;
        }

        $brand = mb_strtolower(trim((string) $product->brand));
        $key = self::nameKey((string) $product->name, $brand);
        $rows = $this->specs()[$brand][$key] ?? [];

        if ($key === '' || count($rows) !== 1 || $this->shopSellsAnotherGeneration($brand, $key)) {
            return null;
        }

        return $rows[0];
    }

    /** Comparable form of a racket name. Deliberately removes very little. */
    public static function nameKey(string $name, string $brand): string
    {
        $key = self::squish(mb_strtolower(str_replace(['™', '®'], '', $name)));
        $key = preg_replace(['/^vợt cầu lông /u', '/ chính hãng$/u'], '', $key) ?? $key;
        if ($brand !== '') {
            $key = preg_replace('/(?<!\S)'.preg_quote(mb_strtolower($brand), '/').'(?!\S)/u', '', $key, 1) ?? $key;
        }

        return self::squish($key);
    }

    private static function squish(string $s): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $s) ?? $s);
    }

    private function shopSellsAnotherGeneration(string $brand, string $key): bool
    {
        $this->shopKeys[$brand] ??= Product::query()
            ->where('category', 'racket')
            ->whereRaw('LOWER(brand) = ?', [$brand])
            ->pluck('name')
            ->map(fn ($name) => self::nameKey((string) $name, $brand))
            ->all();

        $dated = '/^'.preg_quote($key, '/').' (?:\(?(?:năm )?20\d\d\)?|new)(?!\S)/u';

        return preg_grep($dated, $this->shopKeys[$brand]) !== [];
    }

    /** @return array<string, array<string, list<RacketSpec>>> */
    private function specs(): array
    {
        if ($this->specs === null) {
            $this->specs = [];
            foreach (RacketSpec::query()->orderBy('id')->get() as $row) {
                $brand = mb_strtolower($row->brand);
                $this->specs[$brand][self::nameKey($row->model_name, $brand)][] = $row;
            }
        }

        return $this->specs;
    }
}
