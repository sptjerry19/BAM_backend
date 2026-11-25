<?php

namespace Tests\Feature\ContentStudio;

use App\Services\ContentStudio\Sources\FetchException;
use App\Services\ContentStudio\Sources\SafeHttpFetcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafeHttpFetcherTest extends TestCase
{
    private const FEED = 'https://www.badmintonasia.org/feed/';

    private const ALLOWED = ['www.badmintonasia.org', 'badmintonasia.org'];

    private const RSS = ['Content-Type' => 'application/rss+xml; charset=UTF-8'];

    /** @var list<string> hosts the fetcher asked DNS about */
    private array $resolved = [];

    private function fetcher(array $ips = ['93.184.216.34']): SafeHttpFetcher
    {
        return new SafeHttpFetcher(function (string $host) use ($ips) {
            $this->resolved[] = $host;

            return $ips;
        });
    }

    private function fake(mixed $responses): void
    {
        Http::swap(new Factory());
        Http::fake($responses);
    }

    private function reason(callable $fn): string
    {
        try {
            $fn();
        } catch (FetchException $e) {
            return $e->reason;
        }

        return 'no-exception';
    }

    public function test_a_normal_https_feed_from_an_allowed_host_is_fetched(): void
    {
        $this->fake(['*' => Http::response('<rss/>', 200, self::RSS)]);

        $this->assertSame('<rss/>', $this->fetcher()->get(self::FEED, self::ALLOWED, ['xml']));
        Http::assertSent(fn ($r) => $r->hasHeader('User-Agent', config('content-studio.news.fetch.user_agent')));
    }

    public function test_url_shape_is_enforced_before_any_request(): void
    {
        $this->fake(['*' => Http::response('x')]);
        $f = $this->fetcher();

        $cases = [
            'http://www.badmintonasia.org/feed/' => 'scheme',
            'ftp://www.badmintonasia.org/feed/' => 'scheme',
            'file:///etc/passwd' => 'invalid_url', // no host at all
            'https://user:pw@www.badmintonasia.org/feed/' => 'credentials_in_url',
            'https://www.badmintonasia.org:8443/feed/' => 'port',
            'https://127.0.0.1/feed/' => 'ip_literal',
            'https://[::1]/feed/' => 'ip_literal',
            'https://169.254.169.254/latest/meta-data/' => 'ip_literal',
            'https://evil.example/feed/' => 'host_not_allowed',
            'https://www.badmintonasia.org.evil.example/feed/' => 'host_not_allowed',
            'not a url' => 'invalid_url',
        ];
        foreach ($cases as $url => $reason) {
            $this->assertSame($reason, $this->reason(fn () => $f->get($url, self::ALLOWED)), $url);
        }
        Http::assertNothingSent();
    }

    public function test_hosts_resolving_to_non_public_addresses_are_refused(): void
    {
        $this->fake(['*' => Http::response('x')]);

        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.9', '192.168.1.1', '169.254.169.254', '100.64.0.5', '0.0.0.0', '198.18.0.1', '::1', 'fc00::1', 'fe80::1', '::ffff:10.0.0.1', '::ffff:127.0.0.1'] as $ip) {
            $this->assertSame('private_address', $this->reason(fn () => $this->fetcher([$ip])->get(self::FEED, self::ALLOWED)), $ip);
        }
        // one bad address among good ones is enough to refuse (DNS answers can be mixed)
        $this->assertSame('private_address', $this->reason(fn () => $this->fetcher(['93.184.216.34', '10.0.0.1'])->get(self::FEED, self::ALLOWED)));
        $this->assertSame('dns', $this->reason(fn () => $this->fetcher([])->get(self::FEED, self::ALLOWED)));
        Http::assertNothingSent();
    }

    public function test_public_addresses_are_accepted(): void
    {
        $f = $this->fetcher();

        foreach (['93.184.216.34', '8.8.8.8', '1.1.1.1', '2606:2800:220:1:248:1893:25c8:1946'] as $ip) {
            $this->assertTrue($f->isPublic($ip), $ip);
        }
        foreach (['10.0.0.1', '100.127.255.254', '::', 'fd12:3456::1'] as $ip) {
            $this->assertFalse($f->isPublic($ip), $ip);
        }
    }

    public function test_redirects_are_followed_by_hand_and_each_hop_is_checked(): void
    {
        $this->fake([
            'https://www.badmintonasia.org/feed/' => Http::response('', 301, ['Location' => 'https://badmintonasia.org/feed/']),
            'https://badmintonasia.org/feed/' => Http::response('<rss/>', 200, self::RSS),
        ]);

        $this->assertSame('<rss/>', $this->fetcher()->get(self::FEED, self::ALLOWED, ['xml']));
        $this->assertSame(['www.badmintonasia.org', 'badmintonasia.org'], $this->resolved, 'the second hop was resolved and checked too');
    }

    public function test_a_redirect_to_another_host_or_an_internal_address_is_refused(): void
    {
        foreach (['https://evil.example/x' => 'host_not_allowed', 'http://badmintonasia.org/feed/' => 'scheme', 'https://169.254.169.254/latest' => 'ip_literal'] as $location => $reason) {
            $this->fake(['*' => Http::response('', 302, ['Location' => $location])]);
            $this->assertSame($reason, $this->reason(fn () => $this->fetcher()->get(self::FEED, self::ALLOWED)), $location);
        }
    }

    public function test_an_allowed_host_that_starts_resolving_to_a_private_address_after_a_redirect_is_refused(): void
    {
        $this->fake(['*' => Http::response('', 302, ['Location' => 'https://badmintonasia.org/feed/'])]);
        $fetcher = new SafeHttpFetcher(fn (string $host) => $host === 'badmintonasia.org' ? ['10.0.0.8'] : ['93.184.216.34']);

        $this->assertSame('private_address', $this->reason(fn () => $fetcher->get(self::FEED, self::ALLOWED)));
    }

    public function test_redirect_loops_are_bounded(): void
    {
        $this->fake(['*' => Http::response('', 302, ['Location' => '/feed/'])]);

        $this->assertSame('too_many_redirects', $this->reason(fn () => $this->fetcher()->get(self::FEED, self::ALLOWED)));
        Http::assertSentCount(4); // first request + 3 redirects (config max_redirects)
    }

    public function test_relative_redirects_resolve_against_the_current_url(): void
    {
        $this->fake([
            'https://www.badmintonasia.org/feed/' => Http::response('', 302, ['Location' => '/news/feed.xml']),
            'https://www.badmintonasia.org/news/feed.xml' => Http::response('<rss/>', 200, self::RSS),
        ]);

        $this->assertSame('<rss/>', $this->fetcher()->get(self::FEED, self::ALLOWED, ['xml']));
    }

    public function test_oversized_or_wrong_type_or_failed_responses_are_refused(): void
    {
        config(['content-studio.news.fetch.max_bytes' => 1000]);

        $this->fake(['*' => Http::response(str_repeat('a', 2000), 200, self::RSS)]);
        $this->assertSame('too_large', $this->reason(fn () => $this->fetcher()->get(self::FEED, self::ALLOWED, ['xml'])));

        $this->fake(['*' => Http::response('small', 200, self::RSS + ['Content-Length' => '5000'])]);
        $this->assertSame('too_large', $this->reason(fn () => $this->fetcher()->get(self::FEED, self::ALLOWED, ['xml'])));

        $this->fake(['*' => Http::response('<html/>', 200, ['Content-Type' => 'text/html'])]);
        $this->assertSame('content_type', $this->reason(fn () => $this->fetcher()->get(self::FEED, self::ALLOWED, ['xml'])));

        foreach ([403, 404, 500] as $status) {
            $this->fake(['*' => Http::response('no', $status)]);
            $this->assertSame('http_status', $this->reason(fn () => $this->fetcher()->get(self::FEED, self::ALLOWED, ['xml'])), "HTTP {$status}");
        }
    }

    public function test_a_redirect_without_location_is_an_error(): void
    {
        $this->fake(['*' => Http::response('', 302)]);

        $this->assertSame('bad_redirect', $this->reason(fn () => $this->fetcher()->get(self::FEED, self::ALLOWED)));
    }
}
