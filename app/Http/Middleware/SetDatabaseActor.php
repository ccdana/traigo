<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le dice a la base quién actúa, para que el historial (`audit_logs`) lo
 * guarde. Se limpia al terminar: la conexión puede reutilizarse.
 */
class SetDatabaseActor
{
    public function handle(Request $request, Closure $next): Response
    {
        self::set($request->user()->getKey());

        try {
            return $next($request);
        } finally {
            self::clear();
        }
    }

    /** Si no se puede limpiar, se cierra la conexión: no debe quedar puesto. */
    private static function clear(): void
    {
        try {
            self::set('');
        } catch (QueryException $e) {
            // No tapa el error de la petición, que es el que importa.
            report($e);
            DB::disconnect();
        }
    }

    private static function set(string $userId): void
    {
        DB::select("select set_config('traigo.user_id', ?, false)", [$userId]);
    }
}
