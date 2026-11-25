<?php

namespace App\Console\Commands\ContentStudio;

use App\Services\ContentStudio\Sources\Specs\ManufacturerSpecImporter;
use Illuminate\Console\Command;

class ImportSpecs extends Command
{
    protected $signature = 'content:import-specs
        {--brand= : yonex | victor (default: all configured)}
        {--limit=25 : max product pages to fetch per brand}
        {--match= : only URLs whose path contains this text}
        {--delay= : seconds between requests (never below robots.txt Crawl-delay)}
        {--dry-run : fetch and parse, store nothing}';

    protected $description = 'Read racket specs from manufacturers\' own product pages (robots.txt respected)';

    public function handle(ManufacturerSpecImporter $importer): int
    {
        $brands = (array) config('content-studio.specs.brands');
        if ($this->option('brand')) {
            $brands = array_intersect_key($brands, [(string) $this->option('brand') => true]);
        }
        if ($brands === []) {
            $this->error('No such brand configured.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($brands as $name => $brand) {
            $r = $importer->import(
                $name, $brand, max(1, (int) $this->option('limit')), $this->option('match') ?: null,
                $this->option('delay') !== null ? (int) $this->option('delay') : null, (bool) $this->option('dry-run'),
            );
            $this->info(sprintf(
                '%s: urls=%d fetched=%d saved=%d unchanged=%d not_racket=%d skipped_by_robots=%d%s',
                $name, $r['urls'], $r['fetched'], $r['saved'], $r['unchanged'], $r['not_racket'], $r['skipped_robots'],
                $this->option('dry-run') ? ' [dry-run]' : '',
            ));
            foreach (array_slice($r['errors'], 0, 5) as $e) {
                $this->warn('  '.$e);
            }
            $failed += $r['errors'] !== [] && $r['fetched'] === 0 ? 1 : 0;
        }

        return $failed === count($brands) ? self::FAILURE : self::SUCCESS;
    }
}
