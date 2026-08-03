<?php

namespace App\Http\Middleware;

use App\Services\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromIp
{
    public function __construct(
        protected LocaleResolver $localeResolver
    ) {
    }

    /**
     * Set application locale based on client IP country (VN → vi, else → en).
     * Override with header X-Locale / X-App-Locale or query ?lang=vi|en.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->localeResolver->resolve($request);

        App::setLocale($locale);
        $request->attributes->set('app_locale', $locale);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
