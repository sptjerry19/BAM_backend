<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // This app is an API and defines no `login` route: redirecting would throw
        // RouteNotFoundException (HTTP 500) for any client that omits
        // `Accept: application/json`. Returning null yields a plain 401 instead.
        return $request->expectsJson() || ! Route::has('login') ? null : route('login');
    }
}
