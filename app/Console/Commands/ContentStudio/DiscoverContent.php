<?php

namespace App\Console\Commands\ContentStudio;

use App\Services\ContentStudio\Sources\OfficialNewsProvider;
use Illuminate\Console\Command;

class DiscoverContent extends Command
{
    protected $signature = 'content:discover {--dry-run : Fetch and parse, store nothing}';

    protected $description = 'Read the configured news feeds and store new items as verified-source candidates';

    public function handle(OfficialNewsProvider $news): int
    {
        $feeds = (array) config('content-studio.news.feeds');
        if ($feeds === []) {
            $this->warn('No news feeds configured (content-studio.news.feeds). Nothing to do.');

            return self::SUCCESS;
        }

        $report = $news->ingest((bool) $this->option('dry-run'));

        $failed = 0;
        foreach ($report as $r) {
            if ($r['status'] === 'error') {
                $failed++;
                $this->error(sprintf('%s: %s', $r['feed'], $r['error']));

                continue;
            }
            $this->info(sprintf(
                '%s: fetched=%d new=%d existing=%d skipped_old=%d%s',
                $r['feed'], $r['fetched'], $r['new'], $r['existing'], $r['skipped_old'], $this->option('dry-run') ? ' [dry-run]' : '',
            ));
            if ($r['fetched'] > 0 && $r['new'] + $r['existing'] === 0) {
                $this->warn("  every item is older than the retention window: the feed looks stale, so no news will be offered from it.");
            }
        }

        // Fail only when every feed failed: one broken feed must not stop the others.
        return $failed === count($report) ? self::FAILURE : self::SUCCESS;
    }
}
