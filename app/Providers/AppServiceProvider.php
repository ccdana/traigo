<?php

namespace App\Providers;

use App\Services\Media\CloudinaryImageStorage;
use App\Services\Media\ImageStorage;
use App\Services\Media\LocalImageStorage;
use App\Services\Store\StoreSettings;
use App\View\Composers\StoreLayoutComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una sola lectura de la configuración por petición.
        $this->app->scoped(StoreSettings::class);

        $this->app->bind(ImageStorage::class, fn () => filled(config('services.cloudinary.url'))
            ? new CloudinaryImageStorage(config('services.cloudinary.url'))
            : new LocalImageStorage);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimits();

        View::composer('components.layouts.store', StoreLayoutComposer::class);
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

        RateLimiter::for('customer-login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower(trim($email)) : '';

            return [
                Limit::perMinute(config('traigo.web.logins_per_minute'))->by("customer:{$email}|{$request->ip()}"),
                Limit::perMinute(config('traigo.web.logins_per_minute_by_ip'))->by("customer-ip:{$request->ip()}"),
            ];
        });
        RateLimiter::for('customer-register', fn (Request $request) => Limit::perHour(config('traigo.web.registrations_per_hour'))
            ->by("register:{$request->ip()}"));
        RateLimiter::for('password-link', fn (Request $request) => Limit::perMinute(config('traigo.web.password_links_per_minute'))
            ->by("password-link:{$request->ip()}"));
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(config('traigo.web.password_changes_per_minute'))
            ->by("password-change:{$request->ip()}"));

        // La tienda es pública: se limita lo que aparta stock o sube archivos.
        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(config('traigo.web.checkouts_per_minute'))->by("checkout:{$request->ip()}"),
            Limit::perHour(config('traigo.web.checkouts_per_hour'))->by("checkout-hour:{$request->ip()}"),
        ]);
        RateLimiter::for('proof', fn (Request $request) => Limit::perMinute(config('traigo.web.proofs_per_minute'))
            ->by("proof:{$request->ip()}"));
        RateLimiter::for('quote', fn (Request $request) => Limit::perMinute(config('traigo.web.quotes_per_minute'))
            ->by("quote:{$request->ip()}"));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(10)
            ->by("two-factor:{$request->ip()}"));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('traigo.api_requests_per_minute'))
            ->by($request->user()?->getKey() ?: $request->ip()));
    }
}
