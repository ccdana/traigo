<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * El panel web es para almacén, Jime y Dirección con la cuenta activa. Si eso
 * cambia mientras tienen la sesión abierta, se cierra en la siguiente página.
 */
class EnsureWebStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->canUseApp() || ! $user->role->canUseInventoryPanel()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('staff.login')->withErrors(['code' => 'Tu sesión terminó. Ingresa otra vez.']);
        }

        $response = $next($request);

        // Datos internos: ni el navegador ni un proxy deben guardarlos.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
