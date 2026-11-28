<?php

/**
 * API de la app «Traigo Equipo», bajo `/api/v1`. El detalle de cada ruta está
 * en el contrato (repositorio de la app, `docs/api-contract.md`).
 */

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuthorizationController;
use App\Http\Controllers\Api\V1\CashController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\Control;
use App\Http\Controllers\Api\V1\DispatchController;
use App\Http\Controllers\Api\V1\InventoryController;
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
    Route::get('me/attendance', [AttendanceController::class, 'index']);
    Route::put('attendance/site', [AttendanceController::class, 'configure'])->middleware('role:control,management');
    Route::post('me/attendance', [AttendanceController::class, 'store'])->withoutMiddleware('idempotent');

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

    // Almacén: despachos, caja del día y stock.
    Route::middleware('role:warehouse')->group(function () {
        Route::controller(DispatchController::class)->prefix('warehouse')->group(function () {
            Route::get('dispatches', 'index');
            Route::post('orders/{order}/ready', 'ready')->whereUuid('order');
            Route::post('orders/{order}/delivered', 'delivered')->whereUuid('order');
        });
        Route::controller(CashController::class)->prefix('cash')->group(function () {
            Route::get('today', 'today');
            Route::get('days/{date}', 'day')->where('date', '\d{4}-\d{2}-\d{2}');
            Route::post('closings', 'store');
        });
        Route::post('inventory/movements', [InventoryController::class, 'movement']);
        Route::post('inventory/counts', [InventoryController::class, 'storeCount']);
    });
    // Jime también ve los conteos (sin ajustar).
    Route::get('inventory/counts', [InventoryController::class, 'counts'])->middleware('role:warehouse,control');

    // El panel de Jime. Dirección ve el resumen, el cumplimiento, el bono, las
    // comisiones, los precios, el historial y los reportes.
    Route::prefix('control')->group(function () {
        Route::middleware('role:control,management')->group(function () {
            Route::get('summary', [Control\DashboardController::class, 'summary']);
            Route::get('compliance', [Control\DashboardController::class, 'compliance']);
            Route::get('bonus', [Control\ClosingController::class, 'bonus']);
            Route::get('commissions', [Control\CommissionController::class, 'show']);
            Route::get('products/{product}', [Control\ProductController::class, 'show'])->whereUuid('product');
            Route::get('audit', [Control\HistoryController::class, 'audit']);
            Route::get('reports/{type}', [Control\HistoryController::class, 'report'])
                ->whereIn('type', ['week', 'cash', 'commissions']);
        });

        Route::middleware('role:control')->group(function () {
            Route::get('pending', [Control\DashboardController::class, 'pending']);
            Route::post('products/{product}/price-changes', [Control\ProductController::class, 'propose'])
                ->whereUuid('product');
            Route::get('system-status', [Control\HistoryController::class, 'status']);

            Route::controller(Control\MemberController::class)->prefix('members')->group(function () {
                Route::get('', 'index');
                Route::post('{member}/pin', 'resetPin')->whereUuid('member');
                Route::post('{member}/revoke-sessions', 'revoke')->whereUuid('member');
            });

            Route::controller(Control\PaymentController::class)->prefix('payments/{payment}')->group(function () {
                Route::get('', 'show')->whereUuid('payment');
                Route::post('confirm', 'confirm')->whereUuid('payment');
                Route::post('reject', 'reject')->whereUuid('payment');
            });

            Route::controller(Control\KpiController::class)->group(function () {
                Route::get('kpi-results/{result}', 'show')->whereUuid('result');
                Route::post('kpi-results/{result}/validate', 'approve')->whereUuid('result');
                Route::post('kpi-results/{result}/observe', 'observe')->whereUuid('result');
                Route::get('members/{member}/week', 'memberWeek')->whereUuid('member');
            });

            Route::controller(Control\CashClosingController::class)->prefix('cash-closings/{closing}')->group(function () {
                Route::get('', 'show')->whereUuid('closing');
                Route::post('validate', 'approve')->whereUuid('closing');
                Route::post('observe', 'observe')->whereUuid('closing');
            });

            Route::controller(Control\DeliverableController::class)->prefix('deliverables')->group(function () {
                Route::get('', 'index');
                Route::post('{deliverable}/validate', 'approve')->whereUuid('deliverable');
                Route::post('{deliverable}/reject', 'reject')->whereUuid('deliverable');
            });

            Route::controller(Control\OrderController::class)->prefix('orders')->group(function () {
                Route::get('', 'index');
                Route::get('{order}', 'show')->whereUuid('order');
                Route::post('{order}/return', 'returnOrder')->whereUuid('order');
                Route::post('{order}/duplicate', 'duplicate')->whereUuid('order');
            });

            Route::controller(Control\ClosingController::class)->group(function () {
                Route::get('weekly-closing', 'show');
                Route::post('weekly-closing/{week}/send', 'send')->whereNumber('week');
                Route::post('bonus/{week}/members/{member}/paid', 'paid')->whereNumber('week')->whereUuid('member');
            });

            Route::controller(Control\CommissionController::class)->prefix('commissions/{month}')->group(function () {
                Route::post('send', 'send')->where('month', '\d{4}-(0[1-9]|1[0-2])');
                Route::post('paid', 'paid')->where('month', '\d{4}-(0[1-9]|1[0-2])');
            });
        });
    });

    // Dirección autoriza o devuelve.
    Route::middleware('role:management')->controller(AuthorizationController::class)
        ->prefix('management/authorizations')
        ->group(function () {
            // El id es el número de semana, el mes (2026-12) o un UUID.
            $where = ['type' => 'weekly_bonus|commissions|price_change|promotion', 'id' => '[0-9a-f-]{1,36}'];

            Route::get('', 'index');
            Route::get('{type}/{id}', 'show')->where($where);
            Route::post('{type}/{id}/approve', 'approve')->where($where);
            Route::post('{type}/{id}/return', 'sendBack')->where($where);
        });

    // Todas las cuentas del equipo venden y acceden a sus propios pedidos.
    Route::controller(OrderController::class)->group(function () {
        Route::get('orders', 'index');
        Route::get('me/commissions', 'commissions');
        Route::post('orders', 'store');
        Route::get('orders/{order}', 'show')->whereUuid('order');
        Route::post('orders/{order}/cancel', 'cancel')->whereUuid('order');
        Route::post('orders/{order}/payment-proof', 'paymentProof')->whereUuid('order');
    });
});
