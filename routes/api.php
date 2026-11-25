<?php

/**
 * API de la app «Traigo Equipo», bajo `/api/v1`. El detalle de cada ruta está
 * en el contrato (repositorio de la app, `docs/api-contract.md`).
 */

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\TeamController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('throttle:two-factor')->group(function () {
        Route::post('two-factor', [AuthController::class, 'verifyTwoFactor']);
        Route::post('two-factor/resend', [AuthController::class, 'resendTwoFactor']);
    });
});

// Todo lo demás pide sesión. «actor» le dice a la base quién hace el cambio.
Route::middleware(['auth:sanctum', 'staff', 'actor', 'throttle:api', 'idempotent'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    // Inicio, equipo y «Mi semana»: los integrantes, cada quien lo suyo.
    Route::middleware('member')->group(function () {
        Route::get('home', [TeamController::class, 'home']);
        Route::get('team/week', [TeamController::class, 'teamWeek']);
        Route::get('me/week', [TeamController::class, 'myWeek']);
        Route::post('me/kpi-results/{result}/evidence', [SubmissionController::class, 'evidence'])
            ->whereUuid('result');
        Route::post('me/deliverables/{deliverable}/submission', [SubmissionController::class, 'deliverable'])
            ->middleware('role:systems')
            ->whereUuid('deliverable');
    });

    // Catálogo: todo el equipo, y Jime y Dirección para los precios.
    Route::get('catalog', [CatalogController::class, 'index']);
    Route::get('catalog/categories', [CatalogController::class, 'categories']);
    Route::get('catalog/{product}', [CatalogController::class, 'show'])->whereUuid('product');

    // Ventas: solo quienes venden, y cada quien las suyas.
    Route::middleware('role:seller,wholesale')->controller(OrderController::class)->group(function () {
        Route::get('orders', 'index');
        Route::post('orders', 'store');
        Route::get('orders/{order}', 'show')->whereUuid('order');
        Route::post('orders/{order}/cancel', 'cancel')->whereUuid('order');
        Route::post('orders/{order}/payment-proof', 'paymentProof')->whereUuid('order');
    });
});
