<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported locales
    |--------------------------------------------------------------------------
    */
    'supported' => ['en', 'vi'],

    /*
    |--------------------------------------------------------------------------
    | Default locale when IP/country cannot be resolved
    |--------------------------------------------------------------------------
    */
    'default' => env('APP_LOCALE_DEFAULT', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Locale used for local/private IPs (127.0.0.1, 10.x, 192.168.x, ...)
    | Set to "vi" for Vietnam-first local development.
    |--------------------------------------------------------------------------
    */
    'local_ip_locale' => env('APP_LOCALE_LOCAL_IP', 'vi'),

    /*
    |--------------------------------------------------------------------------
    | Country code (ISO 3166-1 alpha-2) => app locale
    | VN => Vietnamese, everything else falls back to default (en)
    |--------------------------------------------------------------------------
    */
    'country_locale_map' => [
        'VN' => 'vi',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds) for IP → country lookups
    |--------------------------------------------------------------------------
    */
    'cache_ttl' => env('APP_LOCALE_CACHE_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Optional GeoIP HTTP endpoint. Uses ip-api.com (HTTP, free, no key).
    | Placeholders: {ip}
    |--------------------------------------------------------------------------
    */
    'geoip_url' => env('APP_GEOIP_URL', 'http://ip-api.com/json/{ip}?fields=status,countryCode'),

];
