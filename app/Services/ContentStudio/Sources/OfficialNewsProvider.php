<?php

namespace App\Services\ContentStudio\Sources;

use App\Models\ContentStudio\ContentSource;
use App\Services\ContentStudio\Ops\ContentEventLogger;

/**
 * Reads the configured news feeds and stores new items as `content_sources`
 * (bucket news). Only feed text is kept; no article page and no image is ever
 * downloaded, so nothing here needs a media licence. A source's `trust`
 * comes from the configuration of its feed, never from the feed content.
 */
final class OfficialNewsProvider
{
    public function __construct(
        private readonly SafeHttpFetcher $fetcher,
        private readonly FeedParser $parser,
        private readonly ContentEventLogger $events,
    ) {}

    /**
     * @return list<array{feed: string, status: string, fetched: int, new: int, existing: int, skipped_old: int, error: ?string}>
     */
    public function ingest(bool $dryRun = false): array
    {
        $report = [];
        foreach ((array) config('content-studio.news.feeds') as $feed) {
            $report[] = $this->ingestFeed($feed, $dryRun);
        }

        $this->events->log('news.ingested', payload: ['dry_run' => $dryRun, 'feeds' => $report]);

        return $report;
    }

    /** @param array{name: string, url: string, trust: string} $feed */
    private function ingestFeed(array $feed, bool $dryRun): array
    {
        $row = ['feed' => $feed['name'], 'status' => 'ok', 'fetched' => 0, 'new' => 0, 'existing' => 0, 'skipped_old' => 0, 'error' => null];

        try {
            $xml = $this->fetcher->get($feed['url'], $this->allowedHosts($feed['url']), ['xml']);
            $items = array_slice($this->parser->parse($xml), 0, (int) config('content-studio.news.max_items_per_feed'));
        } catch (FetchException $e) {
            return [...$row, 'status' => 'error', 'error' => "[{$e->reason}] ".$e->getMessage()];
        }

        $row['fetched'] = count($items);
        $keepAfter = now()->subDays((int) config('content-studio.news.retention_days'));

        foreach ($items as $item) {
            if ($item['published_at']->lessThan($keepAfter)) {
                $row['skipped_old']++;

                continue;
            }

            $hash = ContentSource::hashUrl($item['url']);
            if (ContentSource::query()->where('url_hash', $hash)->exists()) {
                $row['existing']++;

                continue;
            }

            if (! $dryRun) {
                ContentSource::create([
                    'platform' => 'web',
                    'bucket' => 'news',
                    'canonical_url' => $item['url'],
                    'url_hash' => $hash,
                    'creator' => $feed['name'],
                    'title' => $item['title'],
                    'summary' => $item['summary'],
                    'trust' => $feed['trust'],
                    'published_at' => $item['published_at'],
                    'observed_at' => now(),
                    'media_type' => 'text',
                ]);
            }
            $row['new']++;
        }

        return $row;
    }

    /** The feed host and its www / non-www twin: the only hosts a redirect may lead to. @return list<string> */
    private function allowedHosts(string $feedUrl): array
    {
        $host = strtolower((string) parse_url($feedUrl, PHP_URL_HOST));
        $twin = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;

        return [$host, $twin];
    }
}
