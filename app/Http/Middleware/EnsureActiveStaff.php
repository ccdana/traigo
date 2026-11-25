<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si la cuenta se desactivó o dejó de ser del equipo, el token deja de servir
 * (la app sale al ingreso al recibir el 401).
 */
class EnsureActiveStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->canUseApp()) {
            $user?->currentAccessToken()?->delete();

            throw ApiException::unauthorized('Tu sesión terminó. Ingresa otra vez.');
        }

        return $next($request);
    }
}
