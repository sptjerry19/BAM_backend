<?php

namespace App\Services\ContentStudio\Planning;

/**
 * Everything the planner offers to the model, plus the lookup tables the
 * validator needs to check the answer against what was really offered.
 */
final class PlanContext
{
    /**
     * @param  list<string>  $slotsRequested
     * @param  list<array{slot: string, reason: string}>  $shortfalls  decided by code, before asking the model
     * @param  list<array<string, mixed>>  $sources   offered as verified_sources (ids like "s12")
     * @param  list<array<string, mixed>>  $products  product-mode candidates (ids like "p5"); empty in guide mode
     * @param  list<string>  $recentTitles
     */
    public function __construct(
        public readonly string $date,
        public readonly array $slotsRequested,
        public readonly array $shortfalls,
        public readonly array $sources,
        public readonly array $products,
        public readonly string $equipmentMode,
        public readonly array $recentTitles,
    ) {}

    /** @return array<string, array<string, mixed>> offered source id => source row */
    public function sourceIndex(): array
    {
        return array_column($this->sources, null, 'id');
    }

    /** @return array<string, array<string, mixed>> offered product id => facts row */
    public function productIndex(): array
    {
        return array_column($this->products, null, 'id');
    }

    /** What is sent to the model. Contains no credentials and no internal lookup data. @return array<string, mixed> */
    public function toModelInput(): array
    {
        $input = [
            'channel' => config('content-studio.channel.handle'),
            'date' => $this->date,
            'timezone' => config('content-studio.timezone'),
            'slots_requested' => $this->slotsRequested,
            'verified_sources' => array_map(fn (array $s) => [
                'id' => $s['id'],
                'publisher' => $s['publisher'],
                'title' => $s['title'],
                'summary' => $s['summary'],
                'published_at' => $s['published_at'],
            ], $this->sources),
            'equipment' => ['mode' => $this->equipmentMode],
            'topics' => ['tutorial' => config('content-studio.editorial.tutorial_topics')],
            'recent_titles' => $this->recentTitles,
        ];

        if ($this->equipmentMode === 'guide') {
            $input['equipment']['topics'] = config('content-studio.editorial.equipment_topics');
        } else {
            $input['products'] = array_map(fn (array $p) => [
                'id' => $p['id'], 'name' => $p['name'], 'brand' => $p['brand'],
                'facts' => $p['facts'], 'missing_specs' => $p['missing_specs'], 'price' => $p['price'],
            ], $this->products);
        }

        return $input;
    }
}
