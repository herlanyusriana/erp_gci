<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        RateLimiter::for('api-login', function (Request $request) {
            $login = $request->input('email', $request->input('login', ''));
            $login = is_string($login) ? strtolower(trim($login)) : '';

            return [
                Limit::perMinute(60)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('account:'.hash('sha256', $login.'|'.$request->ip())),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
