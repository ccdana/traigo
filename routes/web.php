<?php

/**
 * La web: la tienda pública y el panel de inventario del equipo, bajo
 * `/equipo`. La API de la app está en `routes/api.php`.
 */

use App\Http\Controllers\Staff\AuthorizationController;
use App\Http\Controllers\Staff\InventoryController;
use App\Http\Controllers\Staff\LoginController;
use App\Http\Controllers\Staff\ProductController as StaffProductController;
use App\Http\Controllers\Staff\ProductImageController;
use App\Http\Controllers\Staff\PromotionController;
use App\Http\Controllers\Staff\QuoteController;
use App\Http\Controllers\Staff\SettingsController;
use App\Http\Controllers\Store\Account\PasswordResetController;
use App\Http\Controllers\Store\Account\ProfileController;
use App\Http\Controllers\Store\Account\RegisterController;
use App\Http\Controllers\Store\Account\SessionController;
use App\Http\Controllers\Store\BagController;
use App\Http\Controllers\Store\CategoryController;
use App\Http\Controllers\Store\CheckoutController;
use App\Http\Controllers\Store\HomeController;
use App\Http\Controllers\Store\OrderController;
use App\Http\Controllers\Store\ProductController;
use App\Http\Controllers\Store\WholesaleController;
use Illuminate\Support\Facades\Route;

Route::name('store.')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('categoria/{slug}', CategoryController::class)->name('category');
    Route::get('producto/{slug}', ProductController::class)->name('product');

    Route::middleware('guest:customer')->group(function () {
        Route::controller(RegisterController::class)->prefix('registro')->name('register.')->group(function () {
            Route::get('', 'create')->name('create');
            Route::post('', 'store')->middleware('throttle:customer-register')->name('store');
        });

        Route::get('ingresar', [SessionController::class, 'create'])->name('login');
        Route::post('ingresar', [SessionController::class, 'store'])->middleware('throttle:customer-login')->name('login.store');

        Route::controller(PasswordResetController::class)->name('password.')->group(function () {
            Route::get('olvide-mi-clave', 'request')->name('request');
            Route::post('olvide-mi-clave', 'email')->middleware('throttle:password-link')->name('email');
            Route::get('restablecer-clave/{token}', 'edit')->name('reset');
            Route::post('restablecer-clave', 'update')->middleware('throttle:password-change')->name('update');
        });
    });

    Route::controller(WholesaleController::class)->prefix('por-mayor')->name('wholesale.')->group(function () {
        Route::get('', 'show')->name('show');
        Route::post('cotizacion', 'quote')->middleware('throttle:quote')->name('quote');
    });

    // Comprar y ver pedidos pide cuenta. «auth.session» cierra las demás
    // sesiones al cambiar la contraseña, y «actor» anota quién hace el cambio.
    Route::middleware(['auth:customer', 'customer.account', 'auth.session', 'actor'])->group(function () {
        Route::controller(CheckoutController::class)->prefix('pago')->name('checkout.')->group(function () {
            Route::get('', 'create')->name('create');
            Route::post('', 'store')->middleware('throttle:checkout')->name('store');
        });

        Route::controller(OrderController::class)->group(function () {
            Route::get('mis-pedidos', 'index')->name('order.index');
            Route::get('pedido/{token}', 'show')->name('order.show')->where('token', '[a-f0-9]{32}');
            Route::post('pedido/{token}/comprobante', 'proof')->middleware('throttle:proof')
                ->name('order.proof')->where('token', '[a-f0-9]{32}');
        });

        Route::controller(ProfileController::class)->prefix('mi-cuenta')->name('account.')->group(function () {
            Route::get('', 'edit')->name('edit');
            Route::patch('', 'update')->name('update');
            Route::put('clave', 'password')->middleware('throttle:password-change')->name('password');
        });

        Route::post('salir', [SessionController::class, 'destroy'])->name('logout');
    });

    Route::controller(BagController::class)->prefix('bolsa')->name('bag.')->group(function () {
        Route::get('', 'show')->name('show');
        Route::post('', 'store')->name('store');
        Route::patch('{product}', 'update')->name('update')->whereUuid('product');
        Route::delete('{product}', 'destroy')->name('destroy')->whereUuid('product');
    });
});

Route::prefix('equipo')->name('staff.')->group(function () {
    Route::controller(LoginController::class)->group(function () {
        Route::get('ingresar', 'create')->name('login');
        Route::post('ingresar', 'store')->middleware('throttle:login')->name('login.store');

        Route::get('verificar', 'twoFactor')->name('two-factor.create');
        Route::middleware('throttle:two-factor')->group(function () {
            Route::post('verificar', 'verify')->name('two-factor.verify');
            Route::post('verificar/reenviar', 'resend')->name('two-factor.resend');
        });
    });

    // «actor» le dice a la base quién hace cada cambio, para el historial.
    Route::middleware(['auth:web', 'staff.web', 'actor'])->group(function () {
        Route::post('salir', [LoginController::class, 'destroy'])->name('logout');
        Route::redirect('', '/equipo/inventario');

        // Administración de la tienda: Jime y Dirección (ambos con segundo paso).
        Route::middleware('role:control,management')->group(function () {
            Route::controller(StaffProductController::class)->prefix('productos')->name('products.')->group(function () {
                Route::get('', 'index')->name('index');
                Route::get('nuevo', 'create')->name('create');
                Route::post('', 'store')->name('store');
                Route::get('{product}', 'edit')->name('edit')->whereUuid('product');
                Route::patch('{product}', 'update')->name('update')->whereUuid('product');
                Route::patch('{product}/venta', 'sale')->name('sale')->whereUuid('product');
                Route::post('{product}/precios', 'proposePrice')->name('prices')->middleware('role:control')->whereUuid('product');
            });

            Route::controller(ProductImageController::class)->prefix('productos/{product}/fotos')->name('products.images.')
                ->whereUuid(['product', 'image'])->group(function () {
                    Route::post('', 'store')->name('store');
                    Route::patch('{image}', 'main')->name('main');
                    Route::delete('{image}', 'destroy')->name('destroy');
                });

            Route::controller(PromotionController::class)->prefix('ofertas')->name('promotions.')->group(function () {
                Route::get('', 'index')->name('index');
                Route::patch('{promotion}/retirar', 'withdraw')->name('withdraw')->whereUuid('promotion');
                Route::middleware('role:control')->group(function () {
                    Route::get('nueva', 'create')->name('create');
                    Route::post('', 'store')->name('store');
                });
            });

            Route::controller(SettingsController::class)->prefix('configuracion')->name('settings.')->group(function () {
                Route::get('', 'edit')->name('edit');
                Route::patch('', 'update')->name('update');
                Route::post('qr', 'paymentQr')->name('qr');
                Route::delete('qr', 'removePaymentQr')->name('qr.destroy');
            });
        });

        Route::middleware('role:management')->controller(AuthorizationController::class)
            ->prefix('autorizaciones')->name('authorizations.')->group(function () {
                $where = ['type' => AuthorizationController::TYPES, 'id' => '[0-9a-f-]{36}'];

                Route::get('', 'index')->name('index');
                Route::get('{type}/{id}', 'show')->name('show')->where($where);
                Route::post('{type}/{id}/autorizar', 'approve')->name('approve')->where($where);
                Route::post('{type}/{id}/devolver', 'sendBack')->name('return')->where($where);
            });

        Route::controller(QuoteController::class)->prefix('cotizaciones')->name('quotes.')->group(function () {
            Route::get('', 'index')->name('index');
            Route::patch('{quote}', 'update')->name('update')->whereUuid('quote');
        });

        Route::controller(InventoryController::class)->prefix('inventario')->name('inventory.')->group(function () {
            Route::get('', 'index')->name('index');
            Route::get('{product}', 'show')->name('show')->whereUuid('product');
            Route::post('{product}/movimientos', 'storeMovement')->name('movements.store')->whereUuid('product');
        });
    });
});
