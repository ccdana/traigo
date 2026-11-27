<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * La sesión de cliente solo vale para una cuenta de cliente activa. Si eso
 * cambia con la sesión abierta, se cierra en la siguiente página.
 */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('customer');

        if (! $user?->isActiveCustomer()) {
            Auth::guard('customer')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('store.login')->withErrors(['email' => 'Tu sesión terminó. Ingresa otra vez.']);
        }

        $response = $next($request);

        // Páginas con datos personales: no se guardan en el navegador ni en un proxy.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
