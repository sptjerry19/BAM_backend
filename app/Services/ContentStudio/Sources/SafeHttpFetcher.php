<?php

namespace App\Services\ContentStudio\Sources;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The only way Content Studio fetches a URL that came from configuration or from
 * remote data. Defences against SSRF and resource exhaustion:
 *
 *  - https only, no credentials in the URL, no unusual ports
 *  - the host must be on an explicit allow-list (also after every redirect)
 *  - every address the host resolves to must be public: no loopback, private,
 *    link-local (cloud metadata), CGNAT, reserved or IPv4-mapped-private ranges
 *  - the connection is pinned to the address that was checked (no DNS rebinding)
 *  - manual, bounded redirects; response size and time limits
 */
final class SafeHttpFetcher
{
    /** @var Closure(string): list<string> */
    private Closure $resolver;

    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? $this->defaultResolver(...);
    }

    /** Tests inject a resolver so no real DNS is needed. @param Closure(string): list<string> $resolver */
    public function withResolver(Closure $resolver): self
    {
        $this->resolver = $resolver;

        return $this;
    }

    /**
     * @param  list<string>  $allowedHosts  lower-case host names
     * @param  list<string>  $contentTypes  accepted Content-Type fragments, e.g. ['xml']
     *
     * @throws FetchException
     */
    public function get(string $url, array $allowedHosts, array $contentTypes = [], ?int $maxBytes = null): string
    {
        $cfg = (array) config('content-studio.news.fetch');
        // a caller may raise the cap (sitemaps) but never past 25 MB
        $maxBytes = min($maxBytes ?? (int) $cfg['max_bytes'], 25 * 1024 * 1024);
        $maxRedirects = (int) $cfg['max_redirects'];
        $allowedHosts = array_map('strtolower', $allowedHosts);

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            [$host, $ip] = $this->validate($url, $allowedHosts);

            try {
                $response = Http::withHeaders(['User-Agent' => (string) $cfg['user_agent'], 'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml'])
                    ->withOptions($this->options($host, $ip, $maxBytes))
                    ->connectTimeout(min(10, (int) $cfg['timeout_seconds']))
                    ->timeout((int) $cfg['timeout_seconds'])
                    ->get($url);
            } catch (ConnectionException $e) {
                throw new FetchException('network', 'Network error fetching '.$host.': '.mb_substr($e->getMessage(), 0, 160));
            } catch (Throwable $e) {
                // our own abort from the progress callback, or a transport error
                throw new FetchException($e instanceof FetchException ? $e->reason : 'transport', mb_substr($e->getMessage(), 0, 200));
            }

            if ($response->redirect()) {
                $location = $response->header('Location');
                if ($location === '') {
                    throw new FetchException('bad_redirect', 'Redirect without a Location header.');
                }
                $url = $this->absolute($location, $url);

                continue; // validated again at the top of the loop
            }

            return $this->body($response, $maxBytes, $contentTypes);
        }

        throw new FetchException('too_many_redirects', "More than {$maxRedirects} redirects.");
    }

    /** @return array{0: string, 1: string} host and the (checked) address to connect to */
    private function validate(string $url, array $allowedHosts): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new FetchException('invalid_url', 'Not a valid URL.');
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new FetchException('scheme', 'Only https is allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchException('credentials_in_url', 'Credentials in the URL are not allowed.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new FetchException('port', 'Only the default https port is allowed.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            throw new FetchException('ip_literal', 'IP addresses are not allowed as host.');
        }
        if (! in_array($host, $allowedHosts, true)) {
            throw new FetchException('host_not_allowed', "Host {$host} is not on the allow-list.");
        }

        $addresses = ($this->resolver)($host);
        if ($addresses === []) {
            throw new FetchException('dns', "Could not resolve {$host}.");
        }
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw new FetchException('private_address', "{$host} resolves to a non-public address.");
            }
        }

        return [$host, $addresses[0]];
    }

    public function isPublic(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1): judge the embedded IPv4 address.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            // 100.64.0.0/10 carrier-grade NAT, 192.0.0.0/24 IETF protocol, 198.18.0.0/15 benchmarking
            foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
        } else {
            $packed = (string) inet_pton($ip);
            // fc00::/7 unique local, fe80::/10 link local, ::/128 and ::1 are caught above or here
            $first = ord($packed[0] ?? "\0");
            $second = ord($packed[1] ?? "\0");
            if (($first & 0xFE) === 0xFC || ($first === 0xFE && ($second & 0xC0) === 0x80) || $packed === str_repeat("\0", 16)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function options(string $host, string $ip, int $maxBytes): array
    {
        $options = [
            'allow_redirects' => false, // redirects are followed by hand so each hop is re-validated
            'progress' => function ($downloadTotal, $downloaded) use ($maxBytes) {
                if ($downloaded > $maxBytes) {
                    throw new FetchException('too_large', "Response exceeds {$maxBytes} bytes.");
                }
            },
        ];

        // Connect to the address we just checked, not to whatever DNS says a moment later.
        if (defined('CURLOPT_RESOLVE')) {
            $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:443:{$address}"]];
        }

        return $options;
    }

    /** @param list<string> $contentTypes */
    private function body(Response $response, int $maxBytes, array $contentTypes): string
    {
        if (! $response->successful()) {
            throw new FetchException('http_status', 'HTTP '.$response->status());
        }

        $length = $response->header('Content-Length');
        if ($length !== '' && (int) $length > $maxBytes) {
            throw new FetchException('too_large', "Content-Length {$length} exceeds {$maxBytes} bytes.");
        }

        if ($contentTypes !== []) {
            $type = strtolower($response->header('Content-Type'));
            $ok = false;
            foreach ($contentTypes as $accepted) {
                $ok = $ok || str_contains($type, strtolower($accepted));
            }
            if (! $ok) {
                throw new FetchException('content_type', 'Unexpected Content-Type: '.mb_substr($type, 0, 80));
            }
        }

        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            throw new FetchException('too_large', "Response exceeds {$maxBytes} bytes.");
        }

        return $body;
    }

    private function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin.$dir.'/'.$location;
    }

    /** @return list<string> */
    private function defaultResolver(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_filter($ips));
    }
}
