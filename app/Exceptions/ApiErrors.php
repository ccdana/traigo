<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Convierte cualquier error de `/api/*` al formato del contrato. */
class ApiErrors
{
    /** Errores de PostgreSQL por romper una regla: check, único e historial. */
    private const RULE_STATES = ['23514', '23505', '23001'];

    public static function register(Exceptions $exceptions): void
    {
        // Es una respuesta para el usuario, no una falla del programa: no va
        // al log.
        $exceptions->dontReport(ApiException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return self::toResponse($e);
        });
    }

    private static function toResponse(Throwable $e): ?JsonResponse
    {
        if ($e instanceof ApiException) {
            return self::json($e->status, $e->getMessage(), array_filter([
                'code' => $e->errorCode,
                'data' => $e->data,
            ], fn ($value) => $value !== null), $e->headers);
        }

        if ($e instanceof ValidationException) {
            $errors = $e->errors();

            return self::json($e->status, collect($errors)->flatten()->first(), ['errors' => $errors]);
        }

        if ($e instanceof AuthenticationException) {
            return self::json(401, 'Tu sesión terminó. Ingresa otra vez.');
        }

        if ($e instanceof QueryException && in_array($e->getCode(), self::RULE_STATES, true)) {
            return self::json(409, self::ruleMessage($e));
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = match ($status) {
                403 => 'No tienes permiso para hacer esto.',
                404 => 'No encontramos lo que pediste.',
                405 => 'Esa operación no existe.',
                413 => 'El archivo es demasiado grande.',
                429 => 'Demasiados intentos. Espera un momento.',
                default => $status >= 500
                    ? 'Algo falló en el servidor. Intenta de nuevo.'
                    : 'No se pudo completar la petición.',
            };

            return self::json($status, $message, headers: $e->getHeaders());
        }

        // Con APP_DEBUG se deja el detalle de Laravel para poder corregirlo.
        if (config('app.debug')) {
            return null;
        }

        return self::json(500, 'Algo falló en el servidor. Intenta de nuevo.');
    }

    /**
     * Los disparadores de la base ya escriben sus mensajes en español; los de
     * una restricción (`violates check constraint …`) no se muestran.
     */
    public static function ruleMessage(QueryException $e): string
    {
        $detail = $e->getPrevious()?->getMessage() ?? '';

        if (preg_match('/ERROR:\s+(.+)/u', $detail, $match)
            && ! preg_match('/violates|duplicate key/i', $match[1])) {
            return trim($match[1]);
        }

        return 'No se puede guardar: choca con una regla de los datos.';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $headers
     */
    private static function json(int $status, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(['message' => $message, ...$extra], $status, $headers);
    }
}
