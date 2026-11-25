<?php

use App\Exceptions\ApiErrors;
use App\Http\Middleware\EnsureActiveStaff;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetDatabaseActor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => EnsureActiveStaff::class,
            'role' => EnsureRole::class,
            'actor' => SetDatabaseActor::class,
            'idempotent' => EnsureIdempotency::class,
        ]);

        // La API responde 401; no hay un formulario de ingreso al que enviar.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiErrors::register($exceptions);
    })->create();
