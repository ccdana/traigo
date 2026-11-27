<?php

use App\Models\TwoFactorChallenge;
use App\Services\Store\ExpireWebOrders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Limpieza diaria (el servidor debe ejecutar `php artisan schedule:run` cada
// minuto).
Schedule::call(fn () => TwoFactorChallenge::query()->where('created_at', '<', now()->subDay())->delete())
    ->daily()
    ->name('borrar-codigos-viejos');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Libera el stock de los pedidos web que no se pagaron a tiempo.
Schedule::call(fn () => app(ExpireWebOrders::class)->run())
    ->everyMinute()
    ->name('vencer-pedidos-web')
    ->withoutOverlapping();
