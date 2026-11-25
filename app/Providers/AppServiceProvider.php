<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->configureRateLimits();
    }

    private function configureRateLimits(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $code = $request->input('code');
            $code = is_string($code) ? Str::upper(trim($code)) : '';

            return [
                Limit::perMinute(config('traigo.login.attempts_per_minute'))
                    ->by("login:{$code}|{$request->ip()}"),
                Limit::perMinute(config('traigo.login.attempts_per_minute_by_ip'))
                    ->by("login-ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(10)
            ->by("two-factor:{$request->ip()}"));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('traigo.api_requests_per_minute'))
            ->by($request->user()?->getKey() ?: $request->ip()));
    }
}
