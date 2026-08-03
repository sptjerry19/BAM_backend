<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LocaleResolver
{
    /**
     * Resolve app locale from request (override → IP country → default).
     */
    public function resolve(Request $request): string
    {
        $supported = config('locale.supported', ['en', 'vi']);

        if ($override = $this->fromExplicitOverride($request)) {
            return $this->normalize($override, $supported);
        }

        $ip = $request->ip();

        if ($this->isPrivateIp($ip)) {
            return $this->normalize(
                config('locale.local_ip_locale', 'vi'),
                $supported
            );
        }

        $country = $this->countryFromIp($ip);
        $map = config('locale.country_locale_map', []);

        if ($country && isset($map[$country])) {
            return $this->normalize($map[$country], $supported);
        }

        return $this->normalize(
            config('locale.default', config('app.locale', 'en')),
            $supported
        );
    }

    protected function fromExplicitOverride(Request $request): ?string
    {
        $header = $request->header('X-Locale') ?: $request->header('X-App-Locale');
        if ($header) {
            return $header;
        }

        if ($request->filled('lang')) {
            return $request->query('lang');
        }

        return null;
    }

    protected function countryFromIp(string $ip): ?string
    {
        $cacheKey = 'locale.ip_country.'.$ip;
        $ttl = (int) config('locale.cache_ttl', 86400);

        return Cache::remember($cacheKey, $ttl, function () use ($ip) {
            try {
                $url = str_replace('{ip}', urlencode($ip), config('locale.geoip_url'));
                $response = Http::timeout(2)->get($url);

                if (! $response->successful()) {
                    return null;
                }

                $data = $response->json();
                if (($data['status'] ?? null) !== 'success') {
                    return null;
                }

                $code = strtoupper((string) ($data['countryCode'] ?? ''));

                return $code !== '' ? $code : null;
            } catch (\Throwable $e) {
                Log::debug('Locale geoip lookup failed', [
                    'ip' => $ip,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    protected function isPrivateIp(?string $ip): bool
    {
        if (! $ip) {
            return true;
        }

        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    protected function normalize(string $locale, array $supported): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));
        $short = explode('-', $locale)[0];

        if (in_array($locale, $supported, true)) {
            return $locale;
        }

        if (in_array($short, $supported, true)) {
            return $short;
        }

        // Common aliases
        if (in_array($short, ['vn', 'vie'], true) && in_array('vi', $supported, true)) {
            return 'vi';
        }

        return $supported[0] ?? 'en';
    }
}
