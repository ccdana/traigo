<?php

namespace App\Http\Middleware;

use Closure;
use App\Support\WebAdminAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * El panel web es exclusivo de Dirección con la cuenta activa. Si eso cambia
 * mientras tiene la sesión abierta, se cierra en la siguiente página.
 */
class EnsureWebStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! WebAdminAccess::allows($user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('staff.login')->withErrors(['code' => 'El panel web está habilitado solo para Dirección.']);
        }

        $response = $next($request);

        // Datos internos: ni el navegador ni un proxy deben guardarlos.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
