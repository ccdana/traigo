<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inicio, «Equipo» y «Mi semana» son de los integrantes. Jime y Dirección
 * tienen su panel: para ellos esto no existe (404, como pide el contrato).
 */
class EnsureTeamMember
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->role->requiresSecondStep()) {
            throw ApiException::notFound('Esto es para el equipo.');
        }

        return $next($request);
    }
}
