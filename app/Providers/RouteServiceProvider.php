<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->path().':'.$request->ip());
        });

        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-session', fn (Request $request) => Limit::perMinute(30)->by($request->user('api')?->id ?: $request->ip()));
        RateLimiter::for('checkout-order', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('checkout-booking', fn (Request $request) => Limit::perMinute(8)->by($request->user('api')?->id ?: $request->ip()));
        RateLimiter::for('upload', fn (Request $request) => Limit::perMinute(12)->by($request->user('api')?->id ?: $request->ip()));
        RateLimiter::for('social-upload', fn (Request $request) => Limit::perMinute(20)->by($request->user('api')?->id ?: $request->ip()));
        RateLimiter::for('game-action', fn (Request $request) => [
            Limit::perMinute(120)->by('ip:'.$request->ip()),
            Limit::perMinute(60)->by('player:'.hash('sha256', (string) ($request->header('X-Player-Token') ?: $request->input('player_token') ?: $request->ip()))),
        ]);
        RateLimiter::for('game-room', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
