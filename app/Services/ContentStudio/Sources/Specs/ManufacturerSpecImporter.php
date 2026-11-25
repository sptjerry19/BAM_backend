<?php

namespace App\Services\ContentStudio\Sources\Specs;

use App\Models\ContentStudio\RacketSpec;
use App\Services\ContentStudio\Sources\FetchException;
use App\Services\ContentStudio\Sources\SafeHttpFetcher;
use Closure;

/**
 * Reads racket specs from a manufacturer's OWN product pages.
 *
 * Politeness and safety: robots.txt is read first and every URL is checked against it (a
 * Crawl-delay raises the pause), requests are sequential with a pause, the page cap is
 * explicit, hosts come from config and go through SafeHttpFetcher. Only the spec table is
 * stored (facts), together with the page URL; no text, no photos.
 */
final class ManufacturerSpecImporter
{
    /** @var Closure(int): void */
    private Closure $sleeper;

    public function __construct(private SafeHttpFetcher $http, ?Closure $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param  array<string, mixed>  $brand  entry of content-studio.specs.brands
     * @return array{brand: string, urls: int, fetched: int, saved: int, unchanged: int, skipped_robots: int, not_racket: int, errors: list<string>}
     */
    public function import(string $name, array $brand, int $limit, ?string $match = null, ?int $delay = null, bool $dryRun = false): array
    {
        $host = strtolower((string) $brand['host']);
        $report = ['brand' => $name, 'urls' => 0, 'fetched' => 0, 'saved' => 0, 'unchanged' => 0, 'skipped_robots' => 0, 'not_racket' => 0, 'errors' => []];

        try {
            $robots = RobotsRules::parse($this->http->get("https://{$host}/robots.txt", [$host], ['text/plain', 'text/html']));
            $urls = $this->discover($brand, $host, $match);
        } catch (FetchException $e) {
            $report['errors'][] = $e->getMessage();

            return $report;
        }

        $pause = max($delay ?? (int) config('content-studio.specs.delay_seconds'), $robots->crawlDelay ?? 0);
        $parser = $this->parserFor($name);
        $report['urls'] = count($urls);

        foreach ($urls as $url) {
            if ($report['fetched'] >= $limit) {
                break;
            }
            if (! $robots->allows((string) parse_url($url, PHP_URL_PATH))) {
                $report['skipped_robots']++;

                continue;
            }

            if ($report['fetched'] > 0 && $pause > 0) {
                ($this->sleeper)($pause);
            }
            $report['fetched']++;

            try {
                $parsed = $parser->parse($this->http->get($url, [$host], ['html']));
            } catch (FetchException $e) {
                $report['errors'][] = $url.': '.$e->getMessage();

                continue;
            }
            if ($parsed === null) {
                $report['not_racket']++;

                continue;
            }

            $hash = hash('sha256', json_encode($parsed['specs'], JSON_UNESCAPED_UNICODE));
            $existing = RacketSpec::where('source_url', $url)->first();
            if ($existing !== null && $existing->content_hash === $hash) {
                $report['unchanged']++;
                if (! $dryRun) {
                    $existing->forceFill(['fetched_at' => now()])->save();
                }

                continue;
            }
            if (! $dryRun) {
                RacketSpec::updateOrCreate(['source_url' => $url], [
                    'brand' => $name, 'model_name' => mb_substr($parsed['model'], 0, 190),
                    'specs_json' => $parsed['specs'], 'content_hash' => $hash, 'fetched_at' => now(),
                ]);
            }
            $report['saved']++;
        }

        return $report;
    }

    /** @return list<string> product URLs from the sitemap(s), same host only */
    private function discover(array $brand, string $host, ?string $match): array
    {
        $urls = [];
        foreach ((array) $brand['sitemaps'] as $sitemap) {
            $xml = $this->http->get((string) $sitemap, [$host], ['xml'], 20 * 1024 * 1024);
            preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#', $xml, $m);
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode($loc);
                if (strtolower((string) parse_url($loc, PHP_URL_HOST)) !== $host) {
                    continue;
                }
                $path = (string) parse_url($loc, PHP_URL_PATH);
                if (isset($brand['path_pattern']) && preg_match($brand['path_pattern'], $path) !== 1) {
                    continue;
                }
                if ($match !== null && $match !== '' && ! str_contains($path, $match)) {
                    continue;
                }
                $urls[$loc] = true;
            }
        }

        return array_keys($urls);
    }

    private function parserFor(string $name): SpecParser
    {
        return match ($name) {
            'yonex' => new YonexSpecParser,
            'victor' => new VictorSpecParser,
            default => throw new \InvalidArgumentException("No spec parser for brand {$name}."),
        };
    }
}
